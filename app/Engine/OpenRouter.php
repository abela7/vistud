<?php

namespace App\Engine;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * The engine over OpenRouter (openrouter.ai), or any service with the OpenAI chat format (`VISTUD_ENGINE_URL`), or a
 * provider's own API when the student's key is one (App\Engine\Providers, App\Engine\Credential): OpenAI, Anthropic,
 * Google and the others speak the same format at their own address. With a provider's own key the OpenRouter extras
 * (the cost with every answer, fallback models in one call, the training opt-out) are not there: the cost is worked out
 * from the provider's prices, and a model that can't answer is followed by the fallback here.
 *
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
        return $this->falling($request, function (Request $request) {
            $response = $this->send(fn (PendingRequest $http) => $http->post('/chat/completions', $this->body($request)), $request->key);

            return $this->fromJson($this->json($response, $request->key, $request->model), $request);
        });
    }

    public function stream(Request $request, Closure $onText): Reply
    {
        $heard = false;

        return $this->falling($request, function (Request $request) use ($onText, &$heard) {
            return $this->streamed($request, function (string $words) use ($onText, &$heard) {
                $heard = true;
                $onText($words);
            });
        }, fn () => ! $heard);
    }

    /**
     * A provider's own API names one model a call (OpenRouter takes the fallbacks in the same call): when the model
     * can't answer before saying anything, the fallback is asked instead.
     *
     * @param  Closure(Request): Reply  $ask
     * @param  (Closure(): bool)|null  $untouched  whether nothing has reached the student yet
     */
    private function falling(Request $request, Closure $ask, ?Closure $untouched = null): Reply
    {
        if (! Providers::direct(Credential::provider($request->key)) || $request->fallbacks === []) {
            return $ask($request);
        }
        try {
            return $ask($request);
        } catch (EngineFailed $e) {
            // A refused key or an empty account fails the same with any model; so does an answer already under way.
            if (in_array($e->errorCode, ['engine_bad_key', 'engine_credit'], true) || ($untouched !== null && ! $untouched())) {
                throw $e;
            }

            return $ask(new Request($request->fallbacks[0], $request->system, $request->messages, $request->tools, array_slice($request->fallbacks, 1), $request->maxTokens, $request->noTraining, $request->key));
        }
    }

    private function streamed(Request $request, Closure $onText): Reply
    {
        $timeout = (int) config('vistud.engine.timeout');
        $response = $this->send(
            fn (PendingRequest $http) => $http->timeout($timeout * 3)
                ->accept('text/event-stream')
                ->withOptions(['stream' => true, 'read_timeout' => $timeout])
                ->post('/chat/completions', $this->body($request, stream: true)),
            $request->key,
        );
        if ($response->failed() || ! str_contains(strtolower($response->header('Content-Type')), 'text/event-stream')) {
            // Refused before it began, or a service that answers whole: read it as an unstreamed reply.
            $reply = $this->fromJson($this->json($response, $request->key, $request->model), $request);
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
        $thought = '';
        $direct = Providers::direct(Credential::provider($request->key));
        $this->events($response->toPsrResponse()->getBody(), function (array $chunk) use (&$text, &$calls, &$reasoning, &$usage, &$model, &$finish, &$thought, $direct, $onText) {
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
            // A provider's own API hands its thinking over as words of their own (DeepSeek, Kimi, GLM: `reasoning_content`).
            if ($direct) {
                $thought .= is_string($delta['reasoning_content'] ?? null) ? $delta['reasoning_content'] : (is_string($delta['reasoning'] ?? null) ? $delta['reasoning'] : '');
            }
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
                // Gemini's thought signature rides with the call, whole, and must go back with it.
                if (is_array($part['extra_content'] ?? null)) {
                    $calls[$i]['extra_content'] = array_replace_recursive($calls[$i]['extra_content'] ?? [], $part['extra_content']);
                }
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

        return $this->make(trim($text), $calls, $model, $usage, $finish, $direct ? self::thought($thought) : array_values($reasoning), $request->key);
    }

    /**
     * A provider's thinking, kept the way OpenRouter's is (App\Engine\Reply::$reasoning), so it is saved with its message
     * and goes back to the same model; forProvider() hands it back under the provider's own field.
     *
     * @return list<array<string, string>>
     */
    private static function thought(mixed $text): array
    {
        return is_string($text) && $text !== '' ? [['type' => 'reasoning.text', 'text' => $text, 'format' => 'provider']] : [];
    }

    public function models(?string $key = null): array
    {
        $provider = Credential::provider($key);
        // Some providers list their models elsewhere than beside the chat (Cohere's own API).
        $data = $this->json($this->send(fn (PendingRequest $http) => $http->get((string) Providers::quirk($provider, 'models_url', '/models')), $key), $key);
        if (Providers::direct($provider)) {
            return $this->providerModels($provider, $data);
        }
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

    /**
     * A provider's own list of models, as the app knows them: the ones that chat (not the embedding, speech, image or
     * moderation models a provider lists beside them), with what config/ai_providers.php knows of each (its name,
     * prices, context, tools and pictures); one it doesn't know is offered with tools, without pictures, priced at 0.
     *
     * @param  array<string, mixed>  $data
     * @return list<Model>
     */
    private function providerModels(string $provider, array $data): array
    {
        $skip = (string) Providers::quirk($provider, 'skip', '/(embed|tts|whisper|dall-e|moderation|transcribe|audio|realtime|image|rerank|search|guard|ocr)/i');
        $models = [];
        // {data: [{id}]} as OpenAI's, a bare list (Together), or {models: [{name}]} (Cohere's own API).
        $rows = match (true) {
            is_array($data['data'] ?? null) => $data['data'],
            is_array($data['models'] ?? null) => $data['models'],
            array_is_list($data) => $data,
            default => [],
        };
        foreach ($rows as $row) {
            $raw = is_array($row) ? ($row['id'] ?? $row['name'] ?? null) : null;
            $id = is_string($raw) ? preg_replace('#^models/#', '', $raw) : null;
            if ($id === null || $id === '' || preg_match($skip, $id) === 1) {
                continue;
            }
            $known = Providers::model($provider, $id) ?? [];
            $models[$id] = new Model(
                id: $id,
                name: (string) ($known['name'] ?? $id),
                inPerMillion: (float) ($known['in'] ?? 0),
                outPerMillion: (float) ($known['out'] ?? 0),
                contextLength: (int) ($known['context'] ?? 0),
                tools: (bool) ($known['tools'] ?? true) && Providers::quirk($provider, 'tools', true),
                images: (bool) ($known['images'] ?? false),
                files: false,
            );
        }
        $models = array_values($models);
        usort($models, fn (Model $a, Model $b) => strcasecmp($a->name, $b->name));

        return $models;
    }

    /** @return array<string, mixed> the request in the OpenAI chat shape */
    /**
     * The conversation as a provider's own API takes it: OpenRouter's reasoning is OpenRouter's alone, and so is a
     * call's `extra_content` the provider's that sent it. Gemini wants every call it is shown to carry its thought
     * signature; a call from before it was kept, or from another model, carries the stand-in Google gives for history
     * it didn't sign (ai.google.dev/gemini-api/docs/thought-signatures).
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private static function forProvider(string $provider, array $messages): array
    {
        $signs = (bool) Providers::quirk($provider, 'thought_signatures', false);
        // Thinking that goes back under the provider's own field (`reasoning_content`), and whether it must be there.
        $back = Providers::quirk($provider, 'reasoning_back');
        $needed = (bool) Providers::quirk($provider, 'reasoning_required', false);
        $ids = Providers::quirk($provider, 'tool_call_ids');
        $empty = Providers::quirk($provider, 'empty_content');
        foreach ($messages as &$message) {
            $thinking = is_array($message['reasoning_details'] ?? null) ? implode('', array_map(fn ($r) => is_array($r) && is_string($r['text'] ?? null) ? $r['text'] : '', $message['reasoning_details'])) : '';
            unset($message['reasoning_details'], $message['reasoning_content'], $message['reasoning']);
            if (($message['role'] ?? null) === 'tool' && $ids === 'nine' && is_string($message['tool_call_id'] ?? null)) {
                $message['tool_call_id'] = self::nineId($message['tool_call_id']);
            }
            if (($message['role'] ?? null) !== 'assistant') {
                continue;
            }
            if (is_string($back) && ($thinking !== '' || $needed)) {
                // A turn kept from another model, or from before thinking was kept, has none: DeepSeek refuses an
                // assistant turn without the field when tools are on, so it gets a stand-in.
                $message[$back] = $thinking !== '' ? $thinking : '(not kept)';
            }
            if (is_string($empty) && is_array($message['tool_calls'] ?? null) && ($message['content'] ?? null) === null) {
                $message['content'] = $empty;
            }
            if (! is_array($message['tool_calls'] ?? null)) {
                continue;
            }
            foreach ($message['tool_calls'] as &$call) {
                if (! is_array($call)) {
                    continue;
                }
                if (! $signs) {
                    unset($call['extra_content']);
                } elseif (! is_string($call['extra_content']['google']['thought_signature'] ?? null)) {
                    $call['extra_content']['google']['thought_signature'] = 'skip_thought_signature_validator';
                }
                if ($ids === 'nine' && is_string($call['id'] ?? null)) {
                    $call['id'] = self::nineId($call['id']);
                }
            }
            unset($call);
        }
        unset($message);

        return $messages;
    }

    /**
     * A tool call's id as Mistral takes it: nine letters and digits, the same for the call and its result. Its own ids
     * already are, and stay as they are; another model's (`call_…`, `toolu_…`) are turned into one the same way each time.
     */
    private static function nineId(string $id): string
    {
        return preg_match('/^[A-Za-z0-9]{9}$/', $id) === 1 ? $id : substr(hash('sha256', $id), 0, 9);
    }

    private function body(Request $request, bool $stream = false): array
    {
        $provider = Credential::provider($request->key);
        if (Providers::direct($provider)) {
            // A provider's own API: the plain OpenAI shape, with its quirks (the name of the token limit, usage in a stream).
            $body = [
                'model' => $request->model,
                'messages' => [['role' => 'system', 'content' => $request->system], ...self::forProvider($provider, $request->messages)],
                (string) Providers::quirk($provider, 'max_tokens_field', 'max_tokens') => $request->maxTokens,
            ];
            // A provider whose chat has no tools (Perplexity's) is asked without them, and answers from what it has.
            if ($request->tools !== [] && Providers::quirk($provider, 'tools', true)) {
                $body['tools'] = $request->tools;
                $body['tool_choice'] = 'auto';
            }
            if ($stream) {
                $body['stream'] = true;
                // Z.ai sends a streamed tool call only when asked to.
                if (isset($body['tools']) && Providers::quirk($provider, 'tool_stream', false)) {
                    $body['tool_stream'] = true;
                }
                if (Providers::quirk($provider, 'stream_usage', true)) {
                    $body['stream_options'] = ['include_usage' => true];
                }
            }

            return $body;
        }
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
        // Claude caches only when asked: one marker, which the service moves to the end of what is sent, so the next call
        // reads the standing context, the tools and the chat so far at about a tenth of the price. The request is ordered
        // for it (App\Engine\Context\Stack: what stays the same first). OpenAI, Gemini and DeepSeek cache on their own.
        if (self::asksForCache($request->model)) {
            $body['cache_control'] = ['type' => 'ephemeral'];
        }
        if ($stream) {
            $body['stream'] = true;
        }

        return $body;
    }

    /** Whether a model on OpenRouter caches only when the request asks (Anthropic's Claude). */
    public static function asksForCache(string $model): bool
    {
        return str_starts_with(ltrim($model, '~'), 'anthropic/');
    }

    /** Sends a request with the key (the student's, or the one set up for everyone); a connection that fails says so. */
    private function send(callable $send, ?string $key = null): Response
    {
        [$provider, $secret] = Credential::unpack($key);
        $secret = $secret !== null && $secret !== '' ? $secret : $this->setup->key();
        if ($secret === '') {
            throw new EngineFailed('engine_not_set_up', 'No key for the AI yet. Add your own key in your AI settings, or ask the owner to set one up for everyone.');
        }
        $http = Http::baseUrl(rtrim(Providers::url($provider) ?? $this->setup->url(), '/'))
            ->withToken($secret)
            ->withHeaders([
                'HTTP-Referer' => (string) config('vistud.engine.app_url'), 'X-Title' => (string) config('vistud.engine.app_name'),
                ...(array) Providers::quirk($provider, 'headers', []),
                // A provider that also wants the key under a header of its own (Anthropic's x-api-key).
                ...(is_string($header = Providers::quirk($provider, 'key_header')) ? [$header => $secret] : []),
            ])
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

    /**
     * @return array<string, mixed> the JSON the service answered, once it is known not to be a refusal; a refusal says
     *                              which service, which model and the service's own words, and is logged (never the key)
     */
    private function json(Response $response, ?string $key = null, string $model = ''): array
    {
        $data = $response->json();
        $data = is_array($data) ? $data : [];
        // Google answers an error as a list of one: [{"error": {...}}].
        $error = $data['error'] ?? (is_array($data[0] ?? null) ? ($data[0]['error'] ?? null) : null);
        $said = self::said(is_array($error) ? $error : (is_string($error) ? ['message' => $error] : []));
        if ($response->failed() || $error !== null) {
            $status = $response->status();
            $who = Providers::name(Credential::provider($key));
            $which = $model !== '' ? " ({$model})" : '';
            // Provider error bodies can echo a prompt. Keep them only in the
            // learner's response/encrypted Troubles cache, never in logs.
            Log::warning('The AI service refused a call.', ['provider' => Credential::provider($key), 'model' => $model, 'status' => $status]);
            throw new EngineFailed(match ($status) {
                401, 403 => 'engine_bad_key', 402 => 'engine_credit', 429 => 'engine_limit', 404 => 'engine_model', default => 'engine_refused'
            }, match ($status) {
                401, 403 => "{$who} refused the key. Check it in your AI settings.".$said,
                402 => "Your {$who} account is out of credit. Top it up at {$who}.".$said,
                404 => "{$who} doesn't offer that model{$which}, or not with what the chat needs. Choose another in your AI settings.".$said,
                429 => self::quota($said)
                    ? "{$who} won't run this model{$which} on your plan: its quota is used up or zero (a free plan often has none for the strongest models). Turn on billing at {$who}, or choose another model in your AI settings.".$said
                    : "{$who} is limiting this key right now (too many requests). Wait a minute and try again.".$said,
                default => $status >= 500 ? "{$who} is down right now. Try again in a few minutes.".$said : "{$who} refused the request{$which}.".$said,
            });
        }

        return $data;
    }

    /** Whether a 429 is a plan's quota (used up, or none at all) rather than a moment's rate limit. */
    private static function quota(string $said): bool
    {
        return preg_match('/quota|billing|plan|free.?tier|insufficient|exceeded your/i', $said) === 1;
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
            Providers::direct(Credential::provider($request->key))
                ? self::thought($message['reasoning_content'] ?? $message['reasoning'] ?? null)
                : (is_array($message['reasoning_details'] ?? null) ? array_values(array_filter($message['reasoning_details'], 'is_array')) : []),
            $request->key,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $calls  tool calls in the OpenAI shape
     * @param  array<string, mixed>  $usage
     */
    private function make(string $text, array $calls, string $model, array $usage, ?string $finish, array $reasoning = [], ?string $key = null): Reply
    {
        $cost = $usage['cost'] ?? null;
        // A provider's own API doesn't say what an answer cost: worked out from its prices, when they are known.
        $provider = Credential::provider($key);
        if (! is_numeric($cost) && Providers::direct($provider) && ($known = Providers::model($provider, preg_replace('#^models/#', '', $model))) !== null) {
            $cost = ((int) ($usage['prompt_tokens'] ?? 0) * (float) ($known['in'] ?? 0) + (int) ($usage['completion_tokens'] ?? 0) * (float) ($known['out'] ?? 0)) / 1_000_000;
        }

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
            // OpenRouter and OpenAI's shape; DeepSeek's own API names it apart.
            tokensCached: (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? $usage['prompt_cache_hit_tokens'] ?? 0),
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

        return $parts === [] ? '' : ' It said: '.mb_substr((string) preg_replace('/\s+/', ' ', implode(' ', $parts)), 0, 1500);
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
            $out[] = new ToolCall(is_string($call['id'] ?? null) ? $call['id'] : 'call_'.count($out), $function['name'], is_array($arguments) ? $arguments : [],
                is_array($call['extra_content'] ?? null) ? $call['extra_content'] : []);
        }

        return $out;
    }
}
