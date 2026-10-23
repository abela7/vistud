<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Engine\TokenUsage;
use App\Engine\VistudAi;
use App\Livewire\Study\TokenUsagePanel;
use App\Livewire\Study\UsageReport;
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

    public function test_ai_settings_has_one_row_to_the_report(): void
    {
        $this->get(route('settings', ['part' => 'ai']))->assertOk()->assertSee('token-usage-panel', false);
        Livewire::withoutLazyLoading()->test(TokenUsagePanel::class)
            ->assertSee('Token usage')->assertSee('640 today · 640 this month')->assertSee(route('ai-usage'))->assertSee('Open report')
            ->assertDontSee('Reading files');
    }

    public function test_the_report_shows_one_figure_a_chart_over_time_and_one_breakdown_at_a_time(): void
    {
        $this->get(route('ai-usage'))->assertOk()->assertSee('AI usage')->assertSee('usage-report', false);

        Livewire::test(UsageReport::class)
            // This month: the figure, the chart (a column a day of the month), and where it went by area.
            ->assertSee('640')->assertSee('3 answers')->assertSee('Over time')->assertSee('Where it went')
            ->assertSeeInOrder(['Reading files', '53%', 'Study sessions', '23%', 'ViStud AI', '23%'])
            ->assertDontSee('Keeping long chats short')
            ->set('by', 'course')->assertSeeInOrder(['Physics', '77%', 'No course', '23%'])
            ->set('by', 'model')->assertSeeInOrder(['quick', '53%', 'tutor', '47%'])
            // All time: the folding of 40 days ago, and a column a month.
            ->set('period', 'all')->set('by', 'area')->assertSee('1.7k')->assertSee('Keeping long chats short');

        // Today by the hour (24 columns), this week by the day (7), and its readout is the column now.
        $series = app(TokenUsage::class)->series($this->by, 'today');
        $this->assertCount(24, $series);
        $this->assertSame(640, array_sum(array_column($series, 'total')));
        $this->assertCount(7, app(TokenUsage::class)->series($this->by, 'week'));
        $this->assertCount(12, $all = app(TokenUsage::class)->series($this->by, 'all'));
        $this->assertSame(1_740, array_sum(array_column($all, 'total')));
        $this->assertTrue(end($all)['now']);
    }

    public function test_the_charts_top_is_a_round_number_and_a_long_list_ends_in_other(): void
    {
        $this->assertSame([1, 10_000, 2_000, 500, 1_000], [UsageReport::nice(1), UsageReport::nice(8_300), UsageReport::nice(1_200), UsageReport::nice(500), UsageReport::nice(999)]);
        $list = [];
        foreach (range(1, 8) as $i) {
            $list["Course {$i}"] = ['calls' => 1, 'in' => 0, 'cached' => 0, 'out' => 0, 'total' => 100, 'cost' => 10];
        }
        $top = TokenUsage::top($list);
        $this->assertCount(7, $top);
        $this->assertSame(['Other', 200, 25], [$top[6]['name'], $top[6]['total'], $top[6]['share']]);
    }

    private function job(string $kind, int $in, int $out, int $cost, string $model, ?string $course, $at): void
    {
        LearnerTables::insert(LearnerScope::of($this->by), 'engine_jobs', [
            'id' => Ids::new(), 'workspace_id' => $course, 'role' => 'reader', 'kind' => $kind, 'status' => 'done', 'model' => $model,
            'tokens_in' => $in, 'tokens_out' => $out, 'cost_micros' => $cost, 'created_at' => $at, 'finished_at' => $at,
        ]);
    }
}
