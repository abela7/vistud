<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Engine\TokenUsage;
use App\Engine\VistudAi;
use App\Livewire\Study\TokenUsagePanel;
use App\Livewire\Workspaces\TutorChat;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Ids;
use App\Study\Sessions;
use App\Study\Workspaces;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Token usage: per chat, and today, this week, this month and all time, by area, course and model (2026-10-23). */
class TokenUsageTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private Principal $by;

    private string $course;

    private string $session;

    private string $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Each answer: 100 tokens in, 50 out, $0.001.
        $this->app->instance(Engine::class, new Fake);
        $user = $this->student();
        $this->actingAs($user);
        $this->by = $this->principal($user);
        $this->ownAiKey($this->by);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        $this->course = app(Workspaces::class)->create($this->by, ['name' => 'Physics'])->id;
        $this->session = app(Sessions::class)->start($this->by, $this->course)->id;
        app(SessionChat::class)->send($this->by, $this->session, 'Hello');
        $ai = app(VistudAi::class);
        $this->conversation = $ai->start($this->by, 'Hi');
        $ai->send($this->by, $this->conversation, 'Hi');
        // A file read in the course, and a long chat folded 40 days ago.
        $this->job('read_file', 300, 40, 2_000, 'fake/quick', $this->course, now());
        $this->job('fold', 1_000, 100, 500, 'fake/quick', null, now()->subDays(40));
    }

    public function test_the_periods_add_up_and_each_breakdown_says_where_the_tokens_went(): void
    {
        $report = app(TokenUsage::class)->report($this->by, 'month');

        // This month: two answers (150 each) and the file read (340).
        $this->assertSame(['calls' => 3, 'in' => 500, 'cached' => 0, 'out' => 140, 'total' => 640, 'cost' => 4_000], $report['totals']['month']);
        $this->assertSame(640, $report['totals']['today']['total']);
        $this->assertSame(1_740, $report['totals']['all']['total']);
        $this->assertSame(['Reading files' => 340, 'Study sessions' => 150, 'ViStud AI' => 150], array_map(fn ($t) => $t['total'], $report['areas']));
        $this->assertSame(['Physics' => 490, 'No course' => 150], array_map(fn ($t) => $t['total'], $report['courses']));
        $this->assertSame(['fake/quick' => 340, 'fake/tutor' => 300], array_map(fn ($t) => $t['total'], $report['models']));

        // All time has the folding of 40 days ago.
        $this->assertSame(1_100, app(TokenUsage::class)->report($this->by, 'all')['areas']['Keeping long chats short']['total']);
    }

    public function test_each_chat_knows_its_own_tokens_and_the_gear_shows_them(): void
    {
        $usage = app(TokenUsage::class);
        $this->assertSame(150, $usage->chat($this->by, 'id', $this->conversation)['total']);
        $this->assertSame(150, $usage->chat($this->by, 'session_id', $this->session)['total']);
        $this->assertSame(0, $usage->chat($this->by, 'id', null)['total']);

        Livewire::test(TutorChat::class, ['vistud' => true, 'conversationId' => $this->conversation])->assertSee('150 tokens in this chat');
    }

    public function test_a_cleared_or_deleted_chat_keeps_its_tokens(): void
    {
        app(VistudAi::class)->clear($this->by, $this->conversation);

        $report = app(TokenUsage::class)->report($this->by, 'month');
        $this->assertSame(640, $report['totals']['month']['total']);
        $this->assertSame(150, $report['areas']['Deleted chats and courses']['total']);
        $this->assertArrayNotHasKey('ViStud AI', $report['areas']);
    }

    public function test_the_ai_settings_page_shows_the_periods_and_switches_the_breakdowns(): void
    {
        $this->get(route('settings', ['part' => 'ai']))->assertOk()->assertSee('token-usage-panel', false);
        Livewire::withoutLazyLoading()->test(TokenUsagePanel::class)
            ->assertSee('Token usage')->assertSee('640 today', false)->assertSee('Reading files')->assertSee('Physics')->assertSee('fake/quick')
            ->assertDontSee('Keeping long chats short')
            ->call('$set', 'period', 'all')->assertSee('Keeping long chats short')->assertSee('1.7k');
    }

    private function job(string $kind, int $in, int $out, int $cost, string $model, ?string $course, $at): void
    {
        LearnerTables::insert(LearnerScope::of($this->by), 'engine_jobs', [
            'id' => Ids::new(), 'workspace_id' => $course, 'role' => 'reader', 'kind' => $kind, 'status' => 'done', 'model' => $model,
            'tokens_in' => $in, 'tokens_out' => $out, 'cost_micros' => $cost, 'created_at' => $at, 'finished_at' => $at,
        ]);
    }
}
