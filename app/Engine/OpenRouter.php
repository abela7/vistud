<?php

namespace App\Engine;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The engine over OpenRouter (openrouter.ai), or any service with the OpenAI chat format (`VISTUD_ENGINE_URL`):
 * one key, every model, and the student chooses which (docs/specs/study-memory.md §6). It asks for the usage
 * and cost with every reply, names the fallback models to try when the first can't answer, and keeps the
 * student's words away from training when the settings say so. The key and the address come from the set-up
 * (App\Engine\Setup). Failures come back as EngineFailed with a plain message; the key is never in one.
 */
final class OpenRouter implements Engine
{
    public function __construct(private Setup $setup) {}

    public function reply(Request $request): Reply
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

        $data = $this->call(fn (PendingRequest $http) => $http->post('/chat/completions', $body), $request->key);
        $choice = $data['choices'][0] ?? null;
        if (! is_array($choice)) {
            throw new EngineFailed('engine_empty', 'The engine sent back an empty answer. Try again.');
        }
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $cost = $usage['cost'] ?? null;

        return new Reply(
            text: self::text($message['content'] ?? ''),
            toolCalls: self::toolCalls($message['tool_calls'] ?? []),
            model: is_string($data['model'] ?? null) ? $data['model'] : $request->model,
            tokensIn: (int) ($usage['prompt_tokens'] ?? 0),
            tokensOut: (int) ($usage['completion_tokens'] ?? 0),
            costMicros: is_numeric($cost) ? (int) round((float) $cost * 1_000_000) : null,
            finish: match ($choice['finish_reason'] ?? null) {
                'stop' => 'stop', 'tool_calls' => 'tool_calls', 'length' => 'length', default => 'other'
            },
        );
    }

    public function models(?string $key = null): array
    {
        $data = $this->call(fn (PendingRequest $http) => $http->get('/models'), $key);
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

    /** @return array<string, mixed> the JSON the service answered */
    private function call(callable $send, ?string $key = null): array
    {
        $key = $key !== null && $key !== '' ? $key : $this->setup->key();
        if ($key === '') {
            throw new EngineFailed('engine_not_set_up', 'No key for the AI engine yet. Add your own OpenRouter key in your AI engine settings, or ask the owner to set one up for everyone.');
        }
        $http = Http::baseUrl(rtrim($this->setup->url(), '/'))
            ->withToken($key)
            ->withHeaders(['HTTP-Referer' => (string) config('vistud.engine.app_url'), 'X-Title' => (string) config('vistud.engine.app_name')])
            ->acceptJson()
            ->timeout((int) config('vistud.engine.timeout'))
            ->connectTimeout(15);

        try {
            /** @var Response $response */
            $response = $send($http);
        } catch (ConnectionException) {
            throw new EngineFailed('engine_unreachable', 'The service could not be reached. Check the internet connection and the service\'s address on the AI engine page.');
        } catch (Throwable) {
            throw new EngineFailed('engine_unreachable', 'The engine could not be reached.');
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];
        $said = is_array($data['error'] ?? null) && is_string($data['error']['message'] ?? null) ? ' It said: '.mb_substr($data['error']['message'], 0, 200) : '';
        if ($response->failed() || isset($data['error'])) {
            throw new EngineFailed('engine_refused', match ($response->status()) {
                401, 403 => 'The service refused the key. Check it in the AI engine settings.',
                402 => 'The engine account is out of credit. Top it up at the service.',
                404 => 'The engine does not know that model, or no provider offers it with what the chat needs.'.$said,
                429 => 'The engine is busy right now. Wait a moment and try again.',
                default => $response->status() >= 500 ? 'The engine is down right now. Try again in a few minutes.' : 'The engine refused the request.'.$said,
            });
        }

        return $data;
    }

    /** The words of a reply: a string, or a list of text parts from some models. */
    private static function text(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (is_array($content)) {
            return trim(implode('', array_map(fn ($part) => is_array($part) && is_string($part['text'] ?? null) ? $part['text'] : '', $content)));
        }

        return '';
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
