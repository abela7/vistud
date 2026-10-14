<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\Jobs\PlanAssignment;
use App\Engine\Jobs\Runner;
use App\Engine\Jobs\UnderstandAssignment;
use App\Engine\Reply;
use App\Engine\Request;
use App\Engine\Settings;
use App\Livewire\Workspaces\AssignmentPlan;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\AssignmentLog;
use App\Study\AssignmentSources;
use App\Study\Files;
use App\Study\Modules;
use App\Study\PlanMaker;
use App\Study\Plans;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** An AI plans an assignment from everything ViStud knows about it, with the model the student picks, and it is checked. */
class PlanAssignmentTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private const REPLY = "Here you go.\n```\n<part title=\"Study joins\" marks=\"20\">\n<step>Read the notes on joins</step>\n</part>\n<part title=\"Write the report\" marks=\"60\">\n<step>Draft the comparison</step>\n<step>Proofread</step>\n</part>\n<milestone date=\"2026-10-14\">Draft done</milestone>\n<milestone date=\"2026-10-30\">Too late</milestone>\n<criterion marks=\"40\">Analysis</criterion>\n```\nAssumptions: none.";

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $joins;

    private ActivityDetails $assignment;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Wednesday 7 October 2026.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true, 'auto_read_files' => false]);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Databases'])->id;
        $week2 = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 2: SQL joins'])->id;
        $this->joins = app(Topics::class)->create($this->by, $this->workspace, 'Joins', $week2)->id;
        $this->assignment = app(Activities::class)->create($this->by, $this->workspace, ['kind' => 'assignment', 'title' => 'Joins report', 'due_on' => '2026-10-20', 'module_id' => $week2]);
    }

    /** The assignment, read by the AI from a brief, so there is an understanding to plan from. */
    private function understood(): void
    {
        app(AssignmentSources::class)->add($this->by, $this->assignment->id, ['kind' => 'brief', 'text' => 'Write a 1,500-word report comparing inner and outer joins.']);
        $this->engine->will(Fake::says(json_encode([
            'summary' => 'A 1,500-word report comparing inner and outer joins.',
            'goal' => 'Show when each join is the right one.',
            'deliverables' => [['what' => 'Report', 'length' => '1,500 words', 'on' => '2026-10-20', 'source' => 'S1']],
            'requirements' => [['text' => 'Use examples from your own database', 'source' => 'S1']],
            'criteria' => [['criterion' => 'Analysis', 'weight' => 40, 'looks_like' => 'Compares with reasons.', 'source' => 'S1']],
            'topics' => [['topic' => 'Joins', 'why' => 'The subject.']],
            'extra_sections' => [['title' => 'Data provided', 'items' => [['text' => 'Use the supplied loans.csv.', 'source' => 'S1']]]],
            'confidence' => 'medium',
        ])));
        app(Runner::class)->now($this->by, new UnderstandAssignment($this->workspace, $this->assignment->id));
    }

    private function plan()
    {
        $this->actingAs($this->ada);

        return Livewire::test(AssignmentPlan::class, ['workspaceId' => $this->workspace, 'activityId' => $this->assignment->id]);
    }

    public function test_the_prompt_carries_everything_known_about_the_assignment(): void
    {
        $this->understood();
        $plans = app(Plans::class);
        $part = $plans->addPart($this->by, $this->assignment->id, 'Research', 30);
        $plans->addStep($this->by, $this->assignment->id, 'Find sources', $part->id);
        app(AssignmentLog::class)->add($this->by, $this->assignment->id, 'plan', 'decision', 'Keep to inner and left joins.');
        // Another thing in the same course, one in another course, and one far too late to matter.
        $activities = app(Activities::class);
        $activities->create($this->by, $this->workspace, ['kind' => 'lab', 'title' => 'Lab 3', 'due_on' => '2026-10-15']);
        $activities->create($this->by, $this->workspace, ['kind' => 'exam', 'title' => 'Final exam', 'due_on' => '2026-12-01']);
        $maths = app(Workspaces::class)->create($this->by, ['name' => 'Maths'])->id;
        $activities->create($this->by, $maths, ['kind' => 'quiz', 'title' => 'Algebra quiz', 'due_on' => '2026-10-22']);

        $prompt = app(PlanMaker::class)->prompt($this->by, $this->assignment->id, '');

        foreach ([
            // The rules, then what it understood, each point with its marks and where it came from.
            'You are a patient study coach', '## What ViStud knows about it', 'version 1, not yet confirmed by the student',
            'The goal: Show when each join is the right one.', '- Report (1,500 words, by 2026-10-20) [S1]', '- Use examples from your own database [S1]', '- Analysis (40%) — full marks: Compares with reasons. [S1]',
            'Data provided:', '- Use the supplied loans.csv. [S1]',
            // Where the student is: the days to work in (14, 4 of them weekend days), and the topic it draws on, not started.
            '## Where the student is', 'Days to work in, today to the deadline day: 14 (4 of them weekend days)', '- Joins: Not started (why: The subject.)',
            // The plan so far, to add to and not repeat.
            '## What is already in the plan', '- Part: Research (30%)', '  - [ ] Find sources',
            // What else is due, in the course and out of it, soonest first; not the far one.
            '## What else is due around then', '- Lab 3 (Lab)', '- Algebra quiz (Quiz, Maths)',
            // What was noted, and no brief to paste.
            '## What was noted about it so far', 'Keep to inner and left joins.', 'The student hasn\'t pasted a brief here',
        ] as $needle) {
            $this->assertStringContainsString($needle, $prompt);
        }
        $this->assertStringNotContainsString('Final exam', $prompt);
        $this->assertStringNotContainsString('{{', $prompt);
        $this->assertLessThan(strpos($prompt, 'Algebra quiz'), strpos($prompt, 'Lab 3'), 'soonest first');
    }

    public function test_before_it_is_understood_the_written_sources_and_file_names_are_given_and_with_nothing_it_is_a_general_plan(): void
    {
        $bare = app(PlanMaker::class)->prompt($this->by, $this->assignment->id, '');
        $this->assertStringContainsString('The student hasn\'t shared the brief', $bare);
        // The rules name every section; only the sections that have something are written out.
        $this->assertStringNotContainsString('## What ViStud knows about it', $bare);
        $this->assertStringNotContainsString('## What is already in the plan', $bare);

        app(AssignmentSources::class)->add($this->by, $this->assignment->id, ['kind' => 'email', 'title' => 'Length', 'text' => 'Keep it to 1,500 words.']);
        app(Files::class)->upload($this->by, 'folder', (string) $this->assignment->folderId, $this->temp("Analysis 40%.\n"), 'Rubric.txt');

        $prompt = app(PlanMaker::class)->prompt($this->by, $this->assignment->id, 'Typed brief here.');
        foreach (['hasn\'t read these into an understanding yet', '### Email · Length', 'Keep it to 1,500 words.', 'Files in its folder (not read here): Rubric.txt', 'Typed brief here.'] as $needle) {
            $this->assertStringContainsString($needle, $prompt);
        }
    }

    public function test_a_plan_is_checked_against_what_the_plan_has_the_marks_and_the_time_left(): void
    {
        $plans = app(Plans::class);
        $plans->addPart($this->by, $this->assignment->id, 'Study joins', 50);
        $plans->addCriterion($this->by, $this->assignment->id, 'Analysis', 70);
        $assignment = app(Activities::class)->find($this->by, $this->assignment->id);

        $tidy = PlanMaker::tidy(PlanMaker::read(self::REPLY), $assignment, $plans->get($this->by, $this->assignment->id), true);

        // "Study joins" and "Analysis" are there already. The new part's 60 and the 50 there are over 100: no marks.
        $this->assertSame(['Write the report'], array_column($tidy['parts'], 'title'));
        $this->assertSame([null], array_column($tidy['parts'], 'marks'));
        $this->assertSame([], $tidy['criteria']);
        // A milestone in the time left stays; one after the deadline loses its date, and the hand-in is not one.
        $this->assertSame([['Draft done', '2026-10-14'], ['Too late', null]], array_map(fn ($m) => [$m['title'], $m['due_on']], $tidy['milestones']));
        $this->assertSame([
            '2 things were already in your plan, so they were left out.',
            'The marks on the sections added up to more than 100 with what is already there, so they were left off. Set them yourself.',
            '1 milestone date fell outside the time left, so it was left blank. Set it yourself.',
        ], $tidy['notes']);

        // With nothing there already, the marks add up to 100 or less and stay.
        $plans->clear($this->by, $this->assignment->id);
        $empty = $plans->get($this->by, $this->assignment->id);
        $kept = PlanMaker::tidy(PlanMaker::read(self::REPLY), $assignment, $empty, true);
        $this->assertSame([20, 60], array_column($kept['parts'], 'marks'));
        $this->assertSame([40], array_column($kept['criteria'], 'marks'));
        $this->assertSame(['1 milestone date fell outside the time left, so it was left blank. Set it yourself.'], $kept['notes']);

        // A course without project tools has no milestones, and says nothing of them.
        $plain = PlanMaker::tidy(PlanMaker::read(self::REPLY), $assignment, $empty, false);
        $this->assertSame([[], []], [$plain['milestones'], $plain['notes']]);

        // The same thing said twice in one reply is one.
        $twice = PlanMaker::tidy(['parts' => [], 'steps' => ['Proofread', ' proofread '], 'criteria' => [], 'milestones' => []], $assignment, $empty, true);
        $this->assertSame(['Proofread'], $twice['steps']);
    }

    public function test_the_ai_makes_the_plan_with_its_rules_and_all_it_knows_under_the_model_that_is_picked(): void
    {
        $this->understood();
        $this->engine->will(function (Request $request) {
            $this->assertSame('fake/quick', $request->model);
            $this->assertStringContainsString('You are a patient study coach', $request->system);
            $this->assertStringContainsString('Course: Databases', $request->system);
            $this->assertStringContainsString('## What ViStud knows about it', $request->messages[0]['content']);
            $this->assertStringContainsString('Plan it around the lab.', $request->messages[0]['content']);

            return Fake::says(self::REPLY);
        });

        $reply = app(Runner::class)->answer($this->by, new PlanAssignment($this->workspace, $this->assignment->id, 'Plan it around the lab.'), 'fake/quick');

        $this->assertSame(self::REPLY, $reply);
        // The run is on the record, as the tutor's role and kind of work.
        $this->assertDatabaseHas('engine_jobs', ['kind' => 'plan_assignment', 'role' => 'tutor', 'target_id' => $this->assignment->id, 'status' => 'done']);
    }

    public function test_without_a_pick_it_is_the_tutors_model_and_an_answer_with_no_plan_in_it_fails(): void
    {
        $this->engine->will(function (Request $request) {
            $this->assertSame('fake/tutor', $request->model);

            return Fake::says('I would be glad to help, but I need the brief first.');
        });

        $this->assertThrows(fn () => app(Runner::class)->answer($this->by, new PlanAssignment($this->workspace, $this->assignment->id)), EngineFailed::class);
        $this->assertDatabaseHas('engine_jobs', ['kind' => 'plan_assignment', 'status' => 'failed', 'error_code' => 'engine_unreadable']);
    }

    public function test_a_plan_cut_off_at_the_length_limit_is_not_kept_half_made(): void
    {
        $this->engine->will(new Reply('<part title="Research"><step>Find sources</step></part><part title="Wri', model: 'fake/tutor', tokensOut: PlanAssignment::ANSWER_TOKENS, finish: 'length'));

        $this->assertThrows(fn () => app(Runner::class)->answer($this->by, new PlanAssignment($this->workspace, $this->assignment->id)), EngineFailed::class);
        $this->assertDatabaseHas('engine_jobs', ['kind' => 'plan_assignment', 'status' => 'failed', 'error_code' => 'engine_cut_off']);
    }

    public function test_on_the_page_the_student_picks_the_ai_looks_the_plan_over_with_what_was_fixed_and_adds_it(): void
    {
        $this->engine->will(function (Request $request) {
            $this->assertSame('fake/quick', $request->model);

            return Fake::says(self::REPLY);
        });

        $page = $this->plan()->call('toggleAi')
            ->set('planModel', 'fake/quick')->call('makePlan')->assertHasNoErrors()
            ->assertSet('reading', true);
        $this->assertSame([], app(Plans::class)->get($this->by, $this->assignment->id)->items, 'nothing is kept until it is added');

        $page->call('addRead')->assertSet('aiOpen', false);
        $plan = app(Plans::class)->get($this->by, $this->assignment->id);
        $this->assertSame([['Study joins', 20], ['Write the report', 60]], array_map(fn ($p) => [$p->title, $p->weight], $plan->parts()));
        $this->assertSame([['Analysis', 40]], array_map(fn ($c) => [$c->title, $c->weight], $plan->criteria()));
        $this->assertSame([], $plan->milestones());

        // Asked again for the same: it is all there, and it is said so rather than added twice.
        $this->engine->will(Fake::says(self::REPLY));
        $page->call('toggleAi')->call('makePlan')->assertSet('reading', false)->assertHasErrors(['reply' => 'Everything in it is already in your plan.']);
        $this->assertCount(2, app(Plans::class)->get($this->by, $this->assignment->id)->parts());
    }

    public function test_a_model_that_is_not_on_offer_is_set_aside_and_a_failure_is_said_plainly(): void
    {
        $this->engine->will(function (Request $request) {
            $this->assertSame('fake/tutor', $request->model, 'a tampered pick is the tutor\'s model');

            return Fake::says('No plan here.');
        });
        $this->plan()->call('toggleAi')->set('planModel', 'someone/expensive-model')->call('makePlan')
            ->assertSet('planModel', '')->assertSet('reading', false)->assertHasErrors('aiRun');

        app(Settings::class)->set($this->by, ['consent' => false]);
        $this->plan()->call('toggleAi')->call('makePlan')->assertHasErrors('aiRun');
    }
}
