<?php

namespace Tests\Feature\Engine;

use App\Engine\OpenRouter;
use App\Engine\Request;
use App\Engine\Usage;
use App\Livewire\Study\EngineSettings;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Platform\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Prompt caching: every call resends the standing context, the tools and the chat so far, and the services bill a
 * repeated start at about a tenth. Claude caches only when asked; ViStud asks, and keeps how much was read from the
 * cache so Usage this month can say so.
 */
class PromptCacheTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vistud.engine.key' => 'test-key', 'vistud.engine.url' => 'https://engine.test/api/v1']);
    }

    private function answer(array $usage): void
    {
        Http::fake(['engine.test/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Fine.'], 'finish_reason' => 'stop']],
            'usage' => $usage,
        ])]);
    }

    public function test_a_claude_model_is_asked_to_cache_and_the_cached_tokens_come_back(): void
    {
        $this->answer(['prompt_tokens' => 9000, 'completion_tokens' => 40, 'cost' => 0.004, 'prompt_tokens_details' => ['cached_tokens' => 8200]]);

        $reply = app(OpenRouter::class)->reply(new Request('anthropic/claude-sonnet-5.5', 'You are the tutor.', [['role' => 'user', 'content' => 'Hi']], key: 'test-key'));

        $this->assertSame([9000, 8200], [$reply->tokensIn, $reply->tokensCached]);
        Http::assertSent(fn ($request) => $request->data()['cache_control'] === ['type' => 'ephemeral']);
    }

    public function test_a_model_that_caches_on_its_own_is_sent_no_marker(): void
    {
        $this->answer(['prompt_tokens' => 5000, 'completion_tokens' => 10]);

        $reply = app(OpenRouter::class)->reply(new Request('openai/gpt-5.5-mini', 'You are the tutor.', [['role' => 'user', 'content' => 'Hi']], key: 'test-key'));

        $this->assertSame(0, $reply->tokensCached);
        Http::assertSent(fn ($request) => ! array_key_exists('cache_control', $request->data()));
    }

    public function test_only_anthropic_models_ask(): void
    {
        $this->assertTrue(OpenRouter::asksForCache('anthropic/claude-opus-5.5'));
        $this->assertTrue(OpenRouter::asksForCache('~anthropic/claude-sonnet-latest'));
        $this->assertFalse(OpenRouter::asksForCache('google/gemini-3-flash'));
        $this->assertFalse(OpenRouter::asksForCache('deepseek/deepseek-v4'));
    }

    public function test_usage_says_how_much_of_the_month_was_read_from_the_cache(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00:00', 'UTC'));
        $by = $this->principal($this->student());
        $this->assertNull(app(Usage::class)->cachedShare($by));

        $scope = LearnerScope::of($by);
        $job = fn (int $in, int $cached, string $at) => LearnerTables::insert($scope, 'engine_jobs', ['id' => Ids::new(), 'role' => 'reader', 'kind' => 'read_file', 'status' => 'done', 'tokens_in' => $in, 'tokens_cached' => $cached, 'created_at' => $at]);
        $job(10_000, 7_000, '2026-10-05 10:00:00');
        $job(10_000, 9_000, '2026-10-06 10:00:00');
        $job(50_000, 0, '2026-09-20 10:00:00'); // last month's

        $this->assertSame(80, app(Usage::class)->cachedShare($by));
    }

    public function test_the_ai_settings_show_the_share(): void
    {
        $this->withoutVite();
        $ada = $this->student();
        $scope = LearnerScope::of($this->principal($ada));
        LearnerTables::insert($scope, 'engine_jobs', ['id' => Ids::new(), 'role' => 'reader', 'kind' => 'read_file', 'status' => 'done', 'tokens_in' => 4_000, 'tokens_cached' => 3_000, 'created_at' => now()]);

        Livewire::actingAs($ada)->test(EngineSettings::class)->assertSee('75% of what was sent to the AI this month was reused');
    }
}
