<?php

namespace Tests\Feature\Engine;

use App\Engine\Credential;
use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\OpenRouter;
use App\Engine\Providers;
use App\Engine\Request;
use App\Engine\Settings;
use App\Platform\Access\Principal;
use App\Platform\Errors\Unprocessable;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * A student's key from OpenRouter or from a provider's own API (the owner's ask, 2026-10-12): whose a key is found by
 * how it looks and by which provider takes it; the tutor gets that provider's strongest model and the quick jobs its
 * cheap one; calls go to the provider in the OpenAI format with its quirks, and what an answer cost is worked out from
 * its prices.
 */
class ProvidersTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private Principal $by;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->app->bind(Engine::class, OpenRouter::class);
        $this->by = $this->principal($this->student());
    }

    private static function list(string ...$ids): array
    {
        return ['object' => 'list', 'data' => array_map(fn (string $id) => ['id' => $id, 'object' => 'model'], $ids)];
    }

    public function test_there_are_more_than_ten_providers_and_a_key_is_tried_where_it_looks_like_it_belongs(): void
    {
        $this->assertGreaterThanOrEqual(10, count(Providers::all()) - 1);
        $this->assertSame('openrouter', Providers::candidates('sk-or-v1-abc')[0]);
        $this->assertSame('anthropic', Providers::candidates('sk-ant-api03-abc')[0]);
        $this->assertSame(['google'], Providers::candidates('AQ.Ab8RN6abcdef'));
        $this->assertSame(['groq'], Providers::candidates('gsk_abcdef'));
        $this->assertSame('openai', Providers::candidates('sk-proj-abc')[0]);
        // A plain sk- key could be several providers'; a key with no look of its own, any of those without a prefix.
        $this->assertContains('deepseek', Providers::candidates('sk-0123456789abcdef'));
        $this->assertContains('mistral', Providers::candidates('AbCdEf0123456789AbCdEf0123456789'));
        $this->assertSame('zai', Providers::candidates(str_repeat('a1', 16).'.Secret123')[0]);

        $this->assertSame(['anthropic', 'sk-ant-x'], Credential::unpack(Credential::pack('anthropic', 'sk-ant-x')));
        $this->assertSame(['openrouter', 'sk-or-x'], Credential::unpack('sk-or-x'));
    }

    public function test_a_providers_key_is_found_out_and_its_models_are_given_the_roles(): void
    {
        Http::fake([
            'api.anthropic.com/v1/models' => Http::response(self::list('claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5')),
            '*' => Http::response(['error' => ['message' => 'no']], 401),
        ]);

        $choices = app(Settings::class)->setKey($this->by, 'sk-ant-api03-'.str_repeat('x', 40), 'own');
        $this->assertSame(['anthropic', 'claude-opus-5-5', 'claude-haiku-5-5', 'claude-haiku-5-5'], [$choices->provider, $choices->tutorModel, $choices->readerModel, $choices->helperModel]);
        $this->assertSame('anthropic', Credential::provider(app(Settings::class)->key($this->by)));
        $this->assertStringContainsString('Anthropic offers 3 models', app(Settings::class)->test($this->by)['message']);
        // Its own API wants the key as x-api-key too, with a version.
        Http::assertSent(fn (Sent $r) => $r->hasHeader('x-api-key') && $r->hasHeader('anthropic-version', '2023-06-01'));
    }

    public function test_a_plain_key_is_tried_on_each_provider_it_could_belong_to(): void
    {
        Http::fake([
            'api.moonshot.ai/v1/models' => Http::response(self::list('kimi-k3', 'kimi-k2.6')),
            '*' => Http::response(['error' => ['message' => 'Invalid key']], 401),
        ]);
        $this->assertSame('moonshot', app(Settings::class)->setKey($this->by, 'sk-'.str_repeat('a', 32), 'own')->provider);
    }

    public function test_a_key_no_provider_takes_is_refused_and_says_so(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid key']], 401)]);
        $refusal = function (string $provider) {
            try {
                app(Settings::class)->setKey($this->by, 'sk-'.str_repeat('b', 32), $provider);
            } catch (Unprocessable $e) {
                return $e->details['fields']['key'][0];
            }
            $this->fail('The key was kept.');
        };
        $this->assertStringContainsString('None of the providers took this key', $refusal('own'));
        $this->assertStringContainsString('Mistral didn\'t take this key', $refusal('mistral'));
        $this->assertNull(app(Settings::class)->key($this->by));
    }

    public function test_a_call_goes_to_the_provider_in_its_shape_and_its_cost_is_worked_out_from_its_prices(): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response([
            'model' => 'gpt-6-luna', 'choices' => [['message' => ['content' => 'Hello.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 200_000],
        ])]);

        $reply = app(Engine::class)->reply(new Request('gpt-6-luna', 'Rules.', [['role' => 'user', 'content' => 'Hi']], key: Credential::pack('openai', 'sk-proj-secret')));

        $this->assertSame('Hello.', $reply->text);
        // 1M tokens in at $0.10 and 0.2M out at $0.50: $0.20.
        $this->assertSame(200_000, $reply->costMicros);
        Http::assertSent(function (Sent $r) {
            $body = $r->data();

            return $r->hasHeader('Authorization', 'Bearer sk-proj-secret') && ($body['max_completion_tokens'] ?? null) === 4000
                && ! isset($body['usage']) && ! isset($body['provider']) && ! isset($body['models']) && $body['messages'][0]['role'] === 'system';
        });
    }

    public function test_a_model_that_cant_answer_is_followed_by_the_fallback_but_a_refused_key_is_not(): void
    {
        Http::fake(['api.deepseek.com/v1/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'Model not found']], 404)
            ->push(['model' => 'deepseek-flash', 'choices' => [['message' => ['content' => 'From the fallback.'], 'finish_reason' => 'stop']], 'usage' => []]),
        ]);
        $request = fn (string $key) => new Request('deepseek-v9', 'Rules.', [['role' => 'user', 'content' => 'Hi']], fallbacks: ['deepseek-flash'], key: Credential::pack('deepseek', $key));
        $this->assertSame('From the fallback.', app(Engine::class)->reply($request('sk-a'))->text);
    }

    public function test_a_refused_key_is_not_tried_again_with_the_fallback(): void
    {
        Http::fake(['api.deepseek.com/v1/chat/completions' => Http::response(['error' => ['message' => 'Invalid key']], 401)]);
        $request = new Request('deepseek-v9', 'Rules.', [['role' => 'user', 'content' => 'Hi']], fallbacks: ['deepseek-flash'], key: Credential::pack('deepseek', 'sk-b'));
        $this->assertThrows(fn () => app(Engine::class)->reply($request), EngineFailed::class, 'refused the key');
        Http::assertSentCount(1);
    }

    public function test_a_plan_with_no_quota_for_a_model_says_so_in_the_providers_words(): void
    {
        // Google's free plan lists Gemini 3.1 Pro but runs none of it, and answers its error as a list of one.
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED',
            'message' => "You exceeded your current quota, please check your plan and billing details.\n* Quota exceeded for metric: generate_content_free_tier_requests, limit: 0, model: gemini-3.1-pro"]]], 429)]);
        $request = new Request('gemini-3.1-pro-preview', 'Rules.', [['role' => 'user', 'content' => 'Hi']], key: Credential::pack('google', 'AIza-k'));

        try {
            app(Engine::class)->reply($request);
            $this->fail('A refused call should fail.');
        } catch (EngineFailed $e) {
            $this->assertSame('engine_limit', $e->errorCode);
            $this->assertStringContainsString("Google Gemini won't run this model (gemini-3.1-pro-preview) on your plan", $e->getMessage());
            $this->assertStringContainsString('limit: 0, model: gemini-3.1-pro', $e->getMessage());
            $this->assertStringNotContainsString('AIza-k', $e->getMessage());
        }
    }

    public function test_a_model_is_tried_with_the_students_own_key_and_what_went_wrong_is_kept(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/openai/models' => Http::response(self::list('models/gemini-3.1-pro-preview', 'models/gemini-3.8-flash')),
            'generativelanguage.googleapis.com/v1beta/openai/chat/completions' => Http::sequence()
                ->push($ok = ['model' => 'gemini-3.8-flash', 'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 1]])
                ->push($none = [['error' => ['message' => 'You exceeded your current quota. limit: 0']]], 429)
                ->push($ok)
                ->push($none, 429)
                ->push($ok),
        ]);
        $settings = app(Settings::class);
        $this->assertFalse($settings->tryModel($this->by, 'gemini-3.8-flash')['ok'], 'Without a key of their own nothing is tried.');

        // Saving the key asks each role's choices in turn: the quick one answers; Pro doesn't on this plan, so the tutor is Flash.
        $choices = $settings->setKey($this->by, 'AIza'.str_repeat('q', 35), 'google');
        $this->assertSame(['gemini-3.8-flash', 'gemini-3.8-flash', ''], [$choices->tutorModel, $choices->readerModel, $choices->fallbackModel]);

        $failed = $settings->tryModel($this->by, 'gemini-3.1-pro-preview');
        $this->assertFalse($failed['ok']);
        $this->assertStringContainsString('on your plan', $failed['message']);
        $worked = $settings->tryModel($this->by, 'gemini-3.8-flash', 'Say OK');
        $this->assertSame([true, 'OK', 'gemini-3.8-flash'], [$worked['ok'], $worked['reply'], $worked['model']]);
        // Tried as asked: one model, no fallback.
        Http::assertSent(fn (Sent $r) => str_ends_with($r->url(), '/chat/completions') && $r->data()['model'] === 'gemini-3.1-pro-preview' && ! isset($r->data()['models']));
    }

    public function test_geminis_thought_signature_goes_back_with_its_call_and_old_calls_carry_the_stand_in(): void
    {
        $signed = ['google' => ['thought_signature' => 'sig-abc']];
        $events = implode("\n\n", array_map(fn (array $chunk) => 'data: '.json_encode($chunk), [
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'files', 'arguments' => ''], 'extra_content' => $signed]]]]]],
            ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{}']]]], 'finish_reason' => 'tool_calls']]],
        ]))."\n\ndata: [DONE]\n\n";
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push($events, 200, ['Content-Type' => 'text/event-stream'])
                ->push(['choices' => [['message' => ['content' => 'Done.'], 'finish_reason' => 'stop']], 'usage' => []]),
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Done.'], 'finish_reason' => 'stop']], 'usage' => []]),
        ]);
        $key = Credential::pack('google', 'AIza-k');
        $reply = app(Engine::class)->stream(new Request('gemini-3.5-flash-lite', 'Rules.', [['role' => 'user', 'content' => 'Hi']], key: $key), fn () => null);
        $this->assertSame($signed, $reply->toolCalls[0]->extra);

        // The next call shows Gemini its own call with the signature, and one from before (unsigned) with the stand-in.
        $old = ['id' => 'call_0', 'type' => 'function', 'function' => ['name' => 'topics', 'arguments' => '{}']];
        $messages = [['role' => 'user', 'content' => 'Hi'], ['role' => 'assistant', 'tool_calls' => [$old, $reply->toolCalls[0]->wire()], 'reasoning_details' => [['type' => 'x']]],
            ['role' => 'tool', 'tool_call_id' => 'call_0', 'content' => '[]'], ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '[]']];
        app(Engine::class)->reply(new Request('gemini-3.5-flash-lite', 'Rules.', $messages, key: $key));
        Http::assertSent(function (Sent $r) {
            $calls = $r->data()['messages'][2]['tool_calls'] ?? null;

            return str_contains($r->url(), 'googleapis') && $calls !== null && ! isset($r->data()['messages'][2]['reasoning_details'])
                && $calls[0]['extra_content']['google']['thought_signature'] === 'skip_thought_signature_validator'
                && $calls[1]['extra_content']['google']['thought_signature'] === 'sig-abc';
        });

        // Another provider isn't sent Google's extra.
        app(Engine::class)->reply(new Request('gpt-6-luna', 'Rules.', $messages, key: Credential::pack('openai', 'sk-proj-k')));
        Http::assertSent(fn (Sent $r) => str_contains($r->url(), 'openai.com') && ! isset($r->data()['messages'][2]['tool_calls'][1]['extra_content']));
    }

    public function test_recommended_models_are_priced_able_to_use_tools_and_link_to_where_their_provider_is_paid(): void
    {
        $picks = Providers::recommended();
        $this->assertSame(['smartest', 'value', 'cheapest'], array_keys($picks));
        $all = array_merge(...array_values($picks));
        foreach ($all as $pick) {
            $this->assertTrue(Providers::model($pick['provider'], $pick['model'])['tools'], "{$pick['model']} must call tools");
            $this->assertStringStartsWith('https://', $pick['url']);
            $this->assertNotNull($pick['session']);
        }
        // About a session: 150k tokens in and 10k out. DeepSeek V4 Pro: 0.15 x 1.32 + 0.01 x 3.96 = $0.2376.
        $this->assertEqualsWithDelta(0.2376, collect($all)->firstWhere('model', 'deepseek-v4-pro')['session'], 0.0001);
        // The cheapest are cheaper than the smartest; a model whose chat has no tools is never suggested.
        $this->assertLessThan(min(array_column($picks['smartest'], 'session')), max(array_column($picks['cheapest'], 'session')));
        $this->assertNotContains('gpt-6-astra', array_column($all, 'model'));
    }

    public function test_each_provider_is_shown_the_tool_loop_the_way_it_takes_it(): void
    {
        $done = ['choices' => [['message' => ['content' => 'Done.'], 'finish_reason' => 'stop']], 'usage' => []];
        $events = implode("\n\n", array_map(fn (array $chunk) => 'data: '.json_encode($chunk), [
            ['choices' => [['delta' => ['reasoning_content' => 'First I look ']]]],
            ['choices' => [['delta' => ['reasoning_content' => 'at the files.', 'tool_calls' => [['index' => 0, 'id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'files', 'arguments' => '{}']]]], 'finish_reason' => 'tool_calls']]],
        ]))."\n\ndata: [DONE]\n\n";
        Http::fake([
            'api.deepseek.com/*' => Http::sequence()->push($events, 200, ['Content-Type' => 'text/event-stream'])->push($done),
            '*' => Http::response($done),
        ]);
        $tools = [['type' => 'function', 'function' => ['name' => 'files', 'parameters' => ['type' => 'object']]]];
        $ask = fn (string $provider, array $messages, bool $stream = false) => $stream
            ? app(Engine::class)->stream(new Request('m', 'Rules.', $messages, $tools, key: Credential::pack($provider, 'k')), fn () => null)
            : app(Engine::class)->reply(new Request('m', 'Rules.', $messages, $tools, key: Credential::pack($provider, 'k')));
        $sent = fn (string $host) => collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Sent $r) => str_contains($r->url(), $host))->last()->data();

        // DeepSeek: its thinking is kept, goes back on its own turn, and a turn from before carries a stand-in.
        $reply = $ask('deepseek', [['role' => 'user', 'content' => 'Hi']], true);
        $this->assertSame('First I look at the files.', $reply->reasoning[0]['text']);
        $old = ['role' => 'assistant', 'tool_calls' => [['id' => 'call_abc-1', 'type' => 'function', 'function' => ['name' => 'topics', 'arguments' => '{}']]]];
        $loop = [['role' => 'user', 'content' => 'Hi'], $old, ['role' => 'tool', 'tool_call_id' => 'call_abc-1', 'content' => '[]'],
            ['role' => 'assistant', 'tool_calls' => array_map(fn ($c) => $c->wire(), $reply->toolCalls), 'reasoning_details' => $reply->reasoning],
            ['role' => 'tool', 'tool_call_id' => 'call_9', 'content' => '[]']];
        $ask('deepseek', $loop);
        $this->assertSame(['(not kept)', 'First I look at the files.'], [$sent('deepseek')['messages'][2]['reasoning_content'], $sent('deepseek')['messages'][4]['reasoning_content']]);
        $this->assertArrayNotHasKey('reasoning_details', $sent('deepseek')['messages'][4]);

        // OpenAI and Groq are sent no one's thinking.
        $ask('openai', $loop);
        $this->assertArrayNotHasKey('reasoning_content', $sent('openai.com')['messages'][4]);

        // Mistral: nine-character ids, the same on the call and on its result; its own are left as they are.
        $ask('mistral', [...$loop, ['role' => 'assistant', 'tool_calls' => [['id' => 'Ab3dE6gH9', 'type' => 'function', 'function' => ['name' => 'files', 'arguments' => '{}']]]], ['role' => 'tool', 'tool_call_id' => 'Ab3dE6gH9', 'content' => '[]']]);
        $mistral = $sent('mistral')['messages'];
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{9}$/', $mistral[2]['tool_calls'][0]['id']);
        $this->assertSame($mistral[2]['tool_calls'][0]['id'], $mistral[3]['tool_call_id']);
        $this->assertSame(['Ab3dE6gH9', 'Ab3dE6gH9'], [$mistral[6]['tool_calls'][0]['id'], $mistral[7]['tool_call_id']]);

        // Qwen: a call with no words says "". Perplexity: no tools at all. Z.ai: tool calls streamed when asked.
        $ask('alibaba', $loop);
        $this->assertSame('', $sent('aliyuncs')['messages'][2]['content']);
        $ask('perplexity', $loop);
        $this->assertArrayNotHasKey('tools', $sent('perplexity'));
        Http::fake(['api.z.ai/*' => Http::response("data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream'])]);
        $ask('zai', [['role' => 'user', 'content' => 'Hi']], true);
        $this->assertTrue($sent('z.ai')['tool_stream']);
    }

    public function test_model_lists_of_every_shape_are_read_and_the_ones_that_dont_chat_are_left_out(): void
    {
        Http::fake([
            'api.cohere.com/v1/models*' => Http::response(['models' => [['name' => 'command-a-plus-05-2026'], ['name' => 'embed-v5']]]),
            'api.together.ai/v1/models' => Http::response([['id' => 'moonshotai/Kimi-K3'], ['id' => 'MiniMaxAI/MiniMax-M3']]),
            'generativelanguage.googleapis.com/*' => Http::response(self::list('models/gemini-3.1-pro-preview', 'models/text-embedding-005')),
        ]);

        $ids = fn (string $provider) => array_map(fn ($m) => $m->id, app(Engine::class)->models(Credential::pack($provider, 'k')));
        $this->assertSame(['command-a-plus-05-2026'], $ids('cohere'));
        $this->assertSame(['moonshotai/Kimi-K3', 'MiniMaxAI/MiniMax-M3'], $ids('together'));
        $this->assertSame(['gemini-3.1-pro-preview'], $ids('google'));
        $known = app(Engine::class)->models(Credential::pack('google', 'k'))[0];
        $this->assertSame(['Gemini 3.1 Pro', 2.0, true], [$known->name, $known->inPerMillion, $known->images]);
    }

    public function test_back_on_openrouter_or_without_a_key_the_owners_models_are_the_ones_again(): void
    {
        Http::fake([
            'api.groq.com/openai/v1/models' => Http::response(self::list('openai/gpt-oss-120b', 'openai/gpt-oss-20b')),
            '*' => Http::response(['error' => ['message' => 'no']], 401),
        ]);
        $settings = app(Settings::class);
        $this->assertSame('openai/gpt-oss-120b', $settings->setKey($this->by, 'gsk_'.str_repeat('z', 40), 'own')->tutorModel);
        $this->assertSame(['openai/gpt-oss-120b', 'openai/gpt-oss-20b'], $settings->get($this->by)->chatModels());

        $back = $settings->setKey($this->by, 'sk-or-v1-'.str_repeat('y', 40));
        $this->assertSame(['openrouter', (string) config('vistud.engine.tutor_model')], [$back->provider, $back->tutorModel]);
        $this->assertSame('sk-or-v1-'.str_repeat('y', 40), $settings->key($this->by));

        $settings->setKey($this->by, 'gsk_'.str_repeat('z', 40), 'groq');
        $gone = $settings->removeKey($this->by);
        $this->assertSame([null, (string) config('vistud.engine.tutor_model')], [$gone->provider, $gone->tutorModel]);
    }
}
