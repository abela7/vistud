<?php

namespace Tests\Feature\Engine;

use App\Engine\EngineFailed;
use App\Engine\Models;
use App\Engine\OpenRouter;
use App\Engine\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The engine over OpenRouter, or any service with the OpenAI chat format (docs/specs/study-memory.md §6). */
class OpenRouterTest extends TestCase
{
    use RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vistud.engine.key' => 'test-key', 'vistud.engine.url' => 'https://engine.test/api/v1', 'vistud.engine.app_name' => 'ViStud', 'vistud.engine.app_url' => 'http://vistud.test']);
    }

    public function test_a_request_is_sent_in_the_openai_shape_with_the_key_the_fallbacks_the_tools_and_no_training(): void
    {
        Http::fake(['engine.test/api/v1/chat/completions' => Http::response([
            'id' => 'gen-1', 'model' => 'openai/gpt-4.1-mini',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'A left join keeps every row of the left table.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 40, 'cost' => 0.00123],
        ])]);

        $reply = app(OpenRouter::class)->reply(new Request('openai/gpt-4.1-mini', 'You are the tutor.', [['role' => 'user', 'content' => 'What is a left join?']], [['type' => 'function', 'function' => ['name' => 'topics']]], ['google/gemini-flash']));

        $this->assertSame(['A left join keeps every row of the left table.', [], 'openai/gpt-4.1-mini', 1200, 40, 1230, 'stop'], [$reply->text, $reply->toolCalls, $reply->model, $reply->tokensIn, $reply->tokensOut, $reply->costMicros, $reply->finish]);
        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer test-key') && $request->hasHeader('X-Title', 'ViStud') && $request->hasHeader('HTTP-Referer', 'http://vistud.test')
                && $body['model'] === 'openai/gpt-4.1-mini' && $body['models'] === ['openai/gpt-4.1-mini', 'google/gemini-flash']
                && $body['messages'][0] === ['role' => 'system', 'content' => 'You are the tutor.'] && $body['messages'][1]['content'] === 'What is a left join?'
                && $body['tools'][0]['function']['name'] === 'topics' && $body['tool_choice'] === 'auto'
                && $body['usage'] === ['include' => true] && $body['provider'] === ['data_collection' => 'deny'];
        });
    }

    public function test_a_reply_that_asks_for_tools_carries_each_call_with_its_arguments_decoded(): void
    {
        Http::fake(['engine.test/*' => Http::response(['choices' => [['message' => ['content' => null, 'tool_calls' => [
            ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'read_note', 'arguments' => '{"note":"Lecture 3"}']],
            ['id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'topics', 'arguments' => '']],
        ]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]])]);

        $reply = app(OpenRouter::class)->reply(new Request('m', 's', [], noTraining: false));

        $this->assertTrue($reply->wantsTools());
        $this->assertSame(['', 'tool_calls', null], [$reply->text, $reply->finish, $reply->costMicros]);
        $this->assertSame([['call_a', 'read_note', ['note' => 'Lecture 3']], ['call_b', 'topics', []]], array_map(fn ($c) => [$c->id, $c->name, $c->arguments], $reply->toolCalls));
        Http::assertSent(fn ($request) => ! array_key_exists('provider', $request->data()) && ! array_key_exists('models', $request->data()) && ! array_key_exists('tools', $request->data()));
    }

    public function test_failures_become_plain_messages_without_the_key(): void
    {
        $engine = app(OpenRouter::class);
        $statuses = [401 => 'refused the key', 402 => 'out of credit', 404 => 'does not know that model', 429 => 'busy right now', 500 => 'down right now'];
        $sequence = Http::sequence();
        foreach (array_keys($statuses) as $status) {
            $sequence->push(['error' => ['message' => 'Provider said no']], $status);
        }
        // A 200 that carries an error is a failure too.
        $sequence->push(['error' => ['message' => 'No endpoints found that support tool use'], 'choices' => []]);
        Http::fake(['engine.test/*' => $sequence]);
        foreach ($statuses as $status => $words) {
            try {
                $engine->reply(new Request('m', 's', []));
                $this->fail("A {$status} should fail.");
            } catch (EngineFailed $e) {
                $this->assertStringContainsString($words, $e->getMessage(), "For {$status}");
                $this->assertStringNotContainsString('test-key', $e->getMessage());
            }
        }

        $this->expectException(EngineFailed::class);
        $engine->reply(new Request('m', 's', []));
    }

    public function test_without_a_key_nothing_is_sent_and_the_message_says_where_to_put_one(): void
    {
        config(['vistud.engine.key' => '']);
        Http::fake();
        try {
            app(OpenRouter::class)->reply(new Request('m', 's', []));
            $this->fail('Should fail without a key.');
        } catch (EngineFailed $e) {
            $this->assertStringContainsString('AI engine settings', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_a_students_own_key_is_used_before_the_one_set_up_for_everyone(): void
    {
        Http::fake(['engine.test/*' => Http::response(['choices' => [['message' => ['content' => 'Hi'], 'finish_reason' => 'stop']], 'data' => []])]);
        app(OpenRouter::class)->reply(new Request('m', 's', [], key: 'sk-or-own-key'));
        app(OpenRouter::class)->models('sk-or-own-key');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-or-own-key'));
        app(OpenRouter::class)->reply(new Request('m', 's', []));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function test_the_models_are_listed_with_prices_per_million_and_what_they_take_and_kept_for_a_day(): void
    {
        Http::fake(['engine.test/api/v1/models' => Http::response(['data' => [
            ['id' => 'openai/gpt-4.1-mini', 'name' => 'OpenAI: GPT-4.1 Mini', 'pricing' => ['prompt' => '0.0000004', 'completion' => '0.0000016'], 'context_length' => 1047576, 'supported_parameters' => ['tools', 'temperature'], 'architecture' => ['input_modalities' => ['text', 'image', 'file']]],
            ['id' => 'meta/llama-free', 'name' => 'Llama (free)', 'pricing' => ['prompt' => '0', 'completion' => '0'], 'context_length' => 8192, 'supported_parameters' => ['temperature'], 'architecture' => ['input_modalities' => ['text']]],
            ['name' => 'no id'],
        ]])]);
        Cache::flush();

        $models = app(Models::class)->all();
        $this->assertSame(['Llama (free)', 'OpenAI: GPT-4.1 Mini'], array_map(fn ($m) => $m->name, $models));
        $mini = app(Models::class)->find('openai/gpt-4.1-mini');
        $this->assertSame([0.4, 1.6, 1047576, true, true, true], [$mini->inPerMillion, $mini->outPerMillion, $mini->contextLength, $mini->tools, $mini->images, $mini->files]);
        $this->assertSame('$0.40 in · $1.60 out per million tokens', $mini->priceWords());
        $this->assertSame('free', app(Models::class)->find('meta/llama-free')->priceWords());
        $this->assertFalse(app(Models::class)->find('meta/llama-free')->tools);

        // The second call reads the kept list; a fresh one asks again.
        app(Models::class)->all();
        Http::assertSentCount(1);
        app(Models::class)->all(fresh: true);
        Http::assertSentCount(2);
    }

    public function test_an_unreachable_service_gives_an_empty_list_that_is_not_kept(): void
    {
        Cache::flush();
        Http::fake(['engine.test/*' => Http::sequence()->push('', 503)->push(['data' => [['id' => 'a/b', 'name' => 'B', 'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002']]]])]);
        $this->assertSame([], app(Models::class)->all());
        $this->assertCount(1, app(Models::class)->all());
    }
}
