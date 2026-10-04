<?php

namespace App\Engine;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * The engine over OpenRouter (openrouter.ai), or any service with the OpenAI chat format (`VISTUD_ENGINE_URL`):
 * one key, every model, and the student chooses which (docs/specs/study-memory.md §6). It asks for the usage
 * and cost with every reply, names the fallback models to try when the first can't answer, and keeps the
 * student's words away from training when the settings say so. The key and the address come from the set-up
 * (App\Engine\Setup). Failures come back as EngineFailed with a plain message; the key is never in one.
 *
 * A reply can also be streamed: the service sends it as server-sent events (`data: {…}` lines, a chunk of
 * words or of a tool call each, the usage last, then `data: [DONE]`), and every chunk of words goes to the
 * caller as it comes; the reply returned at the end is the same as an unstreamed one.
 */
final class OpenRouter implements Engine
{
    public function __construct(private Setup $setup) {}

    public function reply(Request $request): Reply
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/chat/completions', $this->body($request)), $request->key);

        return $this->fromJson($this->json($response), $request);
    }

    public function stream(Request $request, Closure $onText): Reply
    {
        $timeout = (int) config('vistud.engine.timeout');
        $response = $this->send(
            fn (PendingRequest $http) => $http->timeout($timeout * 3)->withOptions(['stream' => true, 'read_timeout' => $timeout])
                ->post('/chat/completions', $this->body($request) + ['stream' => true]),
            $request->key,
        );
        if ($response->failed() || ! str_contains(strtolower($response->header('Content-Type')), 'text/event-stream')) {
            // Refused before it began, or a service that answers whole: read it as an unstreamed reply.
            $reply = $this->fromJson($this->json($response), $request);
            if ($reply->text !== '') {
                $onText($reply->text);
            }

            return $reply;
        }

        $text = '';
        $calls = [];
        $reasoning = [];
        $usage = [];
        $model = $request->model;
        $finish = null;
        $this->events($response->toPsrResponse()->getBody(), function (array $chunk) use (&$text, &$calls, &$reasoning, &$usage, &$model, &$finish, $onText) {
            if (isset($chunk['error'])) {
                throw new EngineFailed('engine_cut', 'The answer was cut off by the service. Try again.'.self::said(is_array($chunk['error']) ? $chunk['error'] : []));
            }
            if (is_string($chunk['model'] ?? null) && $chunk['model'] !== '') {
                $model = $chunk['model'];
            }
            if (is_array($chunk['usage'] ?? null)) {
                $usage = $chunk['usage'];
            }
            $choice = is_array($chunk['choices'][0] ?? null) ? $chunk['choices'][0] : [];
            $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
            $words = self::text($delta['content'] ?? '', trim: false);
            if ($words !== '') {
                $text .= $words;
                $onText($words);
            }
            foreach (is_array($delta['tool_calls'] ?? null) ? $delta['tool_calls'] : [] as $part) {
                // A tool call comes in pieces: its id and name first, its arguments a few characters at a time.
                $i = (int) ($part['index'] ?? 0);
                $calls[$i] ??= ['id' => null, 'function' => ['name' => '', 'arguments' => '']];
                if (is_string($part['id'] ?? null)) {
                    $calls[$i]['id'] = $part['id'];
                }
                $calls[$i]['function']['name'] .= is_string($part['function']['name'] ?? null) ? $part['function']['name'] : '';
                $calls[$i]['function']['arguments'] .= is_string($part['function']['arguments'] ?? null) ? $part['function']['arguments'] : '';
            }
            foreach (is_array($delta['reasoning_details'] ?? null) ? $delta['reasoning_details'] : [] as $i => $part) {
                // Reasoning comes in pieces too: the words grow, the rest (its id, format, signature) is the latest.
                if (! is_array($part)) {
                    continue;
                }
                $at = (int) ($part['index'] ?? $i);
                $reasoning[$at] ??= [];
                foreach ($part as $field => $value) {
                    $reasoning[$at][$field] = in_array($field, ['text', 'summary', 'data'], true) && is_string($value) ? (($reasoning[$at][$field] ?? '').$value) : $value;
                }
            }
            if (is_string($choice['finish_reason'] ?? null)) {
                $finish = $choice['finish_reason'];
            }
        });
        ksort($calls);
        ksort($reasoning);
        $calls = array_values(array_filter($calls, fn (array $call) => $call['function']['name'] !== ''));
        if (trim($text) === '' && $calls === [] && $finish === null) {
            throw new EngineFailed('engine_empty', 'The engine sent back an empty answer. Try again.');
        }

        return $this->make(trim($text), $calls, $model, $usage, $finish, array_values($reasoning));
    }

    public function models(?string $key = null): array
    {
        $data = $this->json($this->send(fn (PendingRequest $http) => $http->get('/models'), $key));
        $models = [];
        foreach (is_array($data['data'] ?? null) ? $data['data'] : [] as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null)) {
                continue;
            }
            $pricing = is_array($row['pricing'] ?? null) ? $row['pricing'] : [];
            $parameters = is_array($row['supported_parameters'] ?? null) ? $row['supported_parameters'] : [];
            $inputs = is_array($row['architecture']['input_modalities'] ?? null) ? $row['architecture']['input_modalities'] : [];
            $models[] = new Model(
                id: $row['id'],
                name: is_string($row['name'] ?? null) && $row['name'] !== '' ? $row['name'] : $row['id'],
                inPerMillion: round((float) ($pricing['prompt'] ?? 0) * 1_000_000, 6),
                outPerMillion: round((float) ($pricing['completion'] ?? 0) * 1_000_000, 6),
                contextLength: (int) ($row['context_length'] ?? 0),
                tools: in_array('tools', $parameters, true),
                images: in_array('image', $inputs, true),
                files: in_array('file', $inputs, true),
            );
        }
        usort($models, fn (Model $a, Model $b) => strcasecmp($a->name, $b->name));

        return $models;
    }

    // ---------- Inside ----------

    /** @return array<string, mixed> the request in the OpenAI chat shape */
    private function body(Request $request): array
    {
        $body = [
            'model' => $request->model,
            'messages' => [['role' => 'system', 'content' => $request->system], ...$request->messages],
            'max_tokens' => $request->maxTokens,
            'usage' => ['include' => true],
        ];
        if ($request->fallbacks !== []) {
            $body['models'] = array_values(array_unique([$request->model, ...$request->fallbacks]));
        }
        if ($request->tools !== []) {
            $body['tools'] = $request->tools;
            $body['tool_choice'] = 'auto';
        }
        if ($request->noTraining) {
            $body['provider'] = ['data_collection' => 'deny'];
        }

        return $body;
    }

    /** Sends a request with the key (the student's, or the one set up for everyone); a connection that fails says so. */
    private function send(callable $send, ?string $key = null): Response
    {
        $key = $key !== null && $key !== '' ? $key : $this->setup->key();
        if ($key === '') {
            throw new EngineFailed('engine_not_set_up', 'No key for the AI yet. Add your own OpenRouter key in your AI settings, or ask the owner to set one up for everyone.');
        }
        $http = Http::baseUrl(rtrim($this->setup->url(), '/'))
            ->withToken($key)
            ->withHeaders(['HTTP-Referer' => (string) config('vistud.engine.app_url'), 'X-Title' => (string) config('vistud.engine.app_name')])
            ->acceptJson()
            ->timeout((int) config('vistud.engine.timeout'))
            ->connectTimeout(15);

        try {
            return $send($http);
        } catch (ConnectionException) {
            throw new EngineFailed('engine_unreachable', 'The service could not be reached. Check the internet connection and the service\'s address on the AI engine page.');
        } catch (Throwable) {
            throw new EngineFailed('engine_unreachable', 'The engine could not be reached.');
        }
    }

    /** @return array<string, mixed> the JSON the service answered, once it is known not to be a refusal */
    private function json(Response $response): array
    {
        $data = $response->json();
        $data = is_array($data) ? $data : [];
        $said = self::said(is_array($data['error'] ?? null) ? $data['error'] : []);
        if ($response->failed() || isset($data['error'])) {
            throw new EngineFailed('engine_refused', match ($response->status()) {
                401, 403 => 'The service refused the key. Check it in your AI settings.',
                402 => 'The engine account is out of credit. Top it up at the service.',
                404 => 'The engine does not know that model, or no provider offers it with what the chat needs.'.$said,
                429 => 'The engine is busy right now. Wait a moment and try again.',
                default => $response->status() >= 500 ? 'The engine is down right now. Try again in a few minutes.' : 'The engine refused the request.'.$said,
            });
        }

        return $data;
    }

    /** @param array<string, mixed> $data an unstreamed answer */
    private function fromJson(array $data, Request $request): Reply
    {
        $choice = $data['choices'][0] ?? null;
        if (! is_array($choice)) {
            throw new EngineFailed('engine_empty', 'The engine sent back an empty answer. Try again.');
        }
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];

        return $this->make(
            self::text($message['content'] ?? ''),
            is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [],
            is_string($data['model'] ?? null) ? $data['model'] : $request->model,
            is_array($data['usage'] ?? null) ? $data['usage'] : [],
            is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null,
            is_array($message['reasoning_details'] ?? null) ? array_values(array_filter($message['reasoning_details'], 'is_array')) : [],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $calls  tool calls in the OpenAI shape
     * @param  array<string, mixed>  $usage
     */
    private function make(string $text, array $calls, string $model, array $usage, ?string $finish, array $reasoning = []): Reply
    {
        $cost = $usage['cost'] ?? null;

        return new Reply(
            text: $text,
            toolCalls: self::toolCalls($calls),
            model: $model,
            tokensIn: (int) ($usage['prompt_tokens'] ?? 0),
            tokensOut: (int) ($usage['completion_tokens'] ?? 0),
            costMicros: is_numeric($cost) ? (int) round((float) $cost * 1_000_000) : null,
            finish: match ($finish) {
                'stop' => 'stop', 'tool_calls' => 'tool_calls', 'length' => 'length', default => 'other'
            },
            reasoning: $reasoning,
        );
    }

    /**
     * What the service said went wrong, in its words and the provider's behind it (OpenRouter wraps a provider's
     * refusal as "Provider returned error" and keeps the provider's own message in `metadata.raw`).
     *
     * @param  array<string, mixed>  $error
     */
    private static function said(array $error): string
    {
        $parts = [];
        if (is_string($error['message'] ?? null) && trim($error['message']) !== '') {
            $parts[] = trim($error['message']);
        }
        $meta = is_array($error['metadata'] ?? null) ? $error['metadata'] : [];
        $raw = $meta['raw'] ?? null;
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['message'] ?? $raw) : $raw;
        } elseif (is_array($raw)) {
            $raw = $raw['error']['message'] ?? $raw['message'] ?? json_encode($raw);
        }
        if (is_string($raw) && trim($raw) !== '' && trim($raw) !== ($parts[0] ?? null)) {
            $parts[] = (is_string($meta['provider_name'] ?? null) ? $meta['provider_name'].' says: ' : '').trim($raw);
        }

        return $parts === [] ? '' : ' It said: '.mb_substr(implode(' ', $parts), 0, 300);
    }

    /**
     * Reads server-sent events off the body as they arrive and hands each `data:` chunk's JSON to $each;
     * comments (`: OPENROUTER PROCESSING`, sent to keep the line open) and other fields are skipped.
     */
    private function events(StreamInterface $body, Closure $each): void
    {
        $buffer = '';
        $done = false;
        $line = function (string $line) use ($each, &$done) {
            $line = rtrim($line, "\r");
            if ($done || ! str_starts_with($line, 'data:')) {
                return;
            }
            $data = trim(substr($line, 5));
            if ($data === '[DONE]') {
                $done = true;

                return;
            }
            $chunk = json_decode($data, true);
            if (is_array($chunk)) {
                $each($chunk);
            }
        };
        while (! $done && ! $body->eof()) {
            $buffer .= $body->read(8192);
            while (($end = strpos($buffer, "\n")) !== false) {
                $line(substr($buffer, 0, $end));
                $buffer = substr($buffer, $end + 1);
            }
        }
        if ($buffer !== '') {
            $line($buffer);
        }
    }

    /** The words of a reply: a string, or a list of text parts from some models. */
    private static function text(mixed $content, bool $trim = true): string
    {
        $text = match (true) {
            is_string($content) => $content,
            is_array($content) => implode('', array_map(fn ($part) => is_array($part) && is_string($part['text'] ?? null) ? $part['text'] : '', $content)),
            default => '',
        };

        return $trim ? trim($text) : $text;
    }

    /** @return list<ToolCall> */
    private static function toolCalls(mixed $calls): array
    {
        $out = [];
        foreach (is_array($calls) ? $calls : [] as $call) {
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            if (! is_string($function['name'] ?? null)) {
                continue;
            }
            $arguments = $function['arguments'] ?? [];
            $arguments = is_string($arguments) ? json_decode($arguments, true) : $arguments;
            $out[] = new ToolCall(is_string($call['id'] ?? null) ? $call['id'] : 'call_'.count($out), $function['name'], is_array($arguments) ? $arguments : []);
        }

        return $out;
    }
}
