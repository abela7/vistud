<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Request;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Engine\Tools\AssignmentPlanTool;
use App\Engine\Tools\Context;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\AssignmentLog;
use App\Study\Modules;
use App\Study\Plans;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * An assignment worked on for months, across model changes (the review of 2026-10-19): the next model sees every level
 * of the plan, can read the whole log, folds keep the work's state, and done means the last stage.
 */
class AssignmentMemoryTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private Principal $by;

    private string $workspaceId;

    private ActivityDetails $paper;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->by = $this->principal($this->student());
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'fallback_model' => 'fake/plain', 'consent' => true, 'auto_read_files' => false]);
        $this->workspaceId = app(Workspaces::class)->create($this->by, ['name' => 'Nursing'])->id;
        $module = app(Modules::class)->create($this->by, $this->workspaceId, ['title' => 'Research methods'])->id;
        $this->paper = app(Activities::class)->create($this->by, $this->workspaceId, ['title' => 'Research paper', 'kind' => 'assignment', 'module_id' => $module]);
    }

    public function test_the_standing_layer_shows_every_level_of_steps_and_says_it_is_current(): void
    {
        $plans = app(Plans::class);
        $part = $plans->addPart($this->by, $this->paper->id, 'Literature review');
        $search = $plans->addStep($this->by, $this->paper->id, 'Search CINAHL', $part->id);
        $screen = $plans->addStep($this->by, $this->paper->id, 'Screen the 2024 reviews', $search->id);
        $plans->addStep($this->by, $this->paper->id, 'Appraise the rest', $search->id);
        $plans->setState($this->by, $screen->id, 'done');

        $this->engine->will(Fake::says('Noted.'));
        app(SessionChat::class)->sendOnAssignment($this->by, $this->paper->id, 'Where are we?');
        $system = $this->engine->last()->system;

        $this->assertStringContainsString('  - [in progress] Search CINAHL', $system);
        $this->assertStringContainsString('    - [done] Screen the 2024 reviews', $system);
        $this->assertStringContainsString('    - [to do] Appraise the rest', $system);
        $this->assertStringContainsString('the chat is older: go by this', $system);
    }

    public function test_the_whole_log_can_be_read_page_by_page(): void
    {
        $log = app(AssignmentLog::class);
        for ($i = 1; $i <= 40; $i++) {
            $this->travel(1)->days();
            $log->add($this->by, $this->paper->id, 'do', 'decision', "Decision {$i}.");
        }
        $tool = app(AssignmentPlanTool::class);
        $context = new Context($this->workspaceId, null, null, 'UTC', activityId: $this->paper->id);

        $page3 = $tool->run($this->by, $context, ['assignment' => '', 'log_page' => 3]);
        $this->assertStringContainsString('Decision 10.', $page3);
        $this->assertStringContainsString('Decision 1.', $page3);
        $this->assertStringNotContainsString('Decision 11.', $page3);
        $this->assertStringContainsString('<material>', $page3);
        $this->assertStringContainsString('no older entries', $tool->run($this->by, $context, ['assignment' => '', 'log_page' => 4]));
    }

    public function test_an_assignment_chat_folds_into_the_works_state_not_a_lesson(): void
    {
        config(['vistud.engine.fold_at' => 900, 'vistud.engine.keep_recent' => 2]);
        $long = str_repeat('We chose a scoping review of nurse-led clinics. ', 8);
        $chat = app(SessionChat::class);
        $this->engine->will(Fake::says($long), Fake::says($long));
        $chat->sendOnAssignment($this->by, $this->paper->id, 'Turn 1');
        $chat->sendOnAssignment($this->by, $this->paper->id, 'Turn 2');

        $this->engine->will(Fake::says($long), function (Request $request) {
            $this->assertSame('fake/quick', $request->model);
            $this->assertStringContainsString('research question', $request->system);
            $this->assertStringContainsString('decisions made and why', $request->system);
            $this->assertStringContainsString('which steps are open', $request->system);
            $this->assertStringNotContainsString('what still confuses them', $request->system);

            return Fake::says('Scoping review chosen.', 200, 'fake/quick');
        });
        $chat->sendOnAssignment($this->by, $this->paper->id, 'Turn 3');
    }

    public function test_marking_the_work_done_moves_it_to_the_last_stage_and_reopening_keeps_it(): void
    {
        $activities = app(Activities::class);
        $activities->setStage($this->by, $this->paper->id, 'review');

        $activities->setStatus($this->by, $this->paper->id, 'done');
        $this->assertSame(['submit', 'done'], [$activities->find($this->by, $this->paper->id)->stage, $activities->find($this->by, $this->paper->id)->status]);

        $activities->setStatus($this->by, $this->paper->id, 'doing');
        $this->assertSame(['submit', 'doing'], [$activities->find($this->by, $this->paper->id)->stage, $activities->find($this->by, $this->paper->id)->status]);
    }
}
