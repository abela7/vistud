<?php

namespace Tests\Feature\Study;

use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\PlanMaker;
use App\Study\Plans;
use App\Study\PlanStarters;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** An assignment's plan: parts, steps and criteria, with progress and pace (the owner's review, 2026-10-02). */
class PlansTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    private ActivityDetails $essay;

    private Plans $plans;

    private Activities $activities;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Databases']);
        $this->activities = app(Activities::class);
        $this->plans = app(Plans::class);
        $this->essay = $this->activities->create($this->by, $this->databases->id, ['title' => 'Cell essay', 'due_on' => '2026-10-06', 'due_time' => '09:00']);
    }

    public function test_parts_steps_and_criteria_are_added_in_order_and_progress_counts_what_is_ticked(): void
    {
        $research = $this->plans->addPart($this->by, $this->essay->id, 'Research');
        $draft = $this->plans->addPart($this->by, $this->essay->id, '  Draft  ');
        $sources = $this->plans->addStep($this->by, $this->essay->id, 'Find 5 sources', $research->id);
        $this->plans->addStep($this->by, $this->essay->id, 'Take notes', $research->id);
        $this->plans->addStep($this->by, $this->essay->id, 'Write the introduction', $draft->id);
        $this->plans->addStep($this->by, $this->essay->id, 'Email the tutor');
        $this->plans->addCriterion($this->by, $this->essay->id, 'Use of sources');

        $plan = $this->plans->get($this->by, $this->essay->id);
        $this->assertSame(['Research', 'Draft'], array_map(fn ($p) => $p->title, $plan->parts()));
        $this->assertSame(['Find 5 sources', 'Take notes'], array_map(fn ($s) => $s->title, $plan->steps($research->id)));
        $this->assertSame(['Email the tutor'], array_map(fn ($s) => $s->title, $plan->steps()));
        $this->assertSame(['not_yet'], array_map(fn ($c) => $c->state, $plan->criteria()));
        $this->assertSame([0, 4, 0], [$plan->progress()->done, $plan->progress()->total, $plan->progress()->percent]);

        // Ticking the first thing starts a to-do assignment, and progress follows.
        $this->assertSame('todo', $this->activities->find($this->by, $this->essay->id)->status);
        $this->plans->setState($this->by, $sources->id, 'done');
        $this->assertSame('doing', $this->activities->find($this->by, $this->essay->id)->status);
        $progress = $this->plans->get($this->by, $this->essay->id)->progress();
        $this->assertSame([1, 4, 25, false, false], [$progress->done, $progress->total, $progress->percent, $progress->complete(), $progress->weighted]);

        foreach ($plan->steps($research->id) as $step) {
            $this->plans->setState($this->by, $step->id, 'done');
        }
        $this->plans->setState($this->by, $plan->steps($draft->id)[0]->id, 'done');
        $this->plans->setState($this->by, $plan->steps()[0]->id, 'done');
        $this->assertTrue($this->plans->get($this->by, $this->essay->id)->progress()->complete());
    }

    public function test_a_part_without_steps_is_one_thing_to_tick_and_a_part_with_steps_counts_by_its_steps(): void
    {
        $q1 = $this->plans->addPart($this->by, $this->essay->id, 'Question 1');
        $this->plans->addPart($this->by, $this->essay->id, 'Question 2');
        $slides = $this->plans->addPart($this->by, $this->essay->id, 'Slides');
        $this->plans->addStep($this->by, $this->essay->id, 'Outline', $slides->id);
        $this->plans->addStep($this->by, $this->essay->id, 'Design', $slides->id);

        $this->plans->setState($this->by, $q1->id, 'done');
        $progress = $this->plans->get($this->by, $this->essay->id)->progress();
        $this->assertSame([1, 4, 25], [$progress->done, $progress->total, $progress->percent]);
    }

    public function test_when_every_part_has_marks_progress_follows_the_marks_and_loose_steps_share_what_is_left(): void
    {
        $essay = $this->plans->addPart($this->by, $this->essay->id, 'Essay', '60');
        $talk = $this->plans->addPart($this->by, $this->essay->id, 'Talk', 30);
        $this->plans->addStep($this->by, $this->essay->id, 'Draft', $essay->id);
        $this->plans->addStep($this->by, $this->essay->id, 'Proofread', $essay->id);
        $loose = $this->plans->addStep($this->by, $this->essay->id, 'Submit');

        $plan = $this->plans->get($this->by, $this->essay->id);
        $this->plans->setState($this->by, $plan->steps($essay->id)[0]->id, 'done');
        $this->plans->setState($this->by, $talk->id, 'done');
        $this->plans->setState($this->by, $loose->id, 'done');
        // Essay half done (30 of 60), the talk done (30), the loose step done for the 10 left: 70 of 100; 3 of 4 things.
        $progress = $this->plans->get($this->by, $this->essay->id)->progress();
        $this->assertSame([3, 4, 70, true], [$progress->done, $progress->total, $progress->percent, $progress->weighted]);

        // A part without marks puts it back to counting things.
        $this->plans->addPart($this->by, $this->essay->id, 'Reflection');
        $this->assertFalse($this->plans->get($this->by, $this->essay->id)->progress()->weighted);
    }

    public function test_criteria_are_checked_not_yet_partly_or_met_and_scored_by_marks_or_equally(): void
    {
        $analysis = $this->plans->addCriterion($this->by, $this->essay->id, 'Analysis', '40');
        $sources = $this->plans->addCriterion($this->by, $this->essay->id, 'Sources', '60');
        $this->plans->setState($this->by, $analysis->id, 'met');
        $this->plans->setState($this->by, $sources->id, 'partly');
        $score = $this->plans->get($this->by, $this->essay->id)->criteriaScore();
        $this->assertSame([1, 2, 70], [$score['met'], $score['total'], $score['percent']]);

        // Ticking a criterion is not work done: the assignment doesn't start.
        $this->assertSame('todo', $this->activities->find($this->by, $this->essay->id)->status);
        $this->assertThrows(fn () => $this->plans->setState($this->by, $analysis->id, 'done'), Unprocessable::class);
        $step = $this->plans->addStep($this->by, $this->essay->id, 'Draft');
        $this->assertThrows(fn () => $this->plans->setState($this->by, $step->id, 'met'), Unprocessable::class);
    }

    public function test_items_are_renamed_given_marks_moved_and_deleted_and_a_part_takes_its_steps(): void
    {
        $a = $this->plans->addPart($this->by, $this->essay->id, 'Research');
        $b = $this->plans->addPart($this->by, $this->essay->id, 'Draft');
        $c = $this->plans->addPart($this->by, $this->essay->id, 'Finish');
        $step = $this->plans->addStep($this->by, $this->essay->id, 'Find sources', $a->id);

        $edited = $this->plans->edit($this->by, $a->id, 'Research and notes', '25');
        $this->assertSame(['Research and notes', 25], [$edited->title, $edited->weight]);
        $this->assertNull($this->plans->edit($this->by, $step->id, 'Find five sources', '50')->weight);

        $this->plans->move($this->by, $c->id, 'up');
        $this->plans->move($this->by, $a->id, 'up');
        $this->assertSame(['Research and notes', 'Finish', 'Draft'], array_map(fn ($p) => $p->title, $this->plans->get($this->by, $this->essay->id)->parts()));
        $this->plans->move($this->by, $b->id, 'down');
        $this->assertSame(['Research and notes', 'Finish', 'Draft'], array_map(fn ($p) => $p->title, $this->plans->get($this->by, $this->essay->id)->parts()));

        $this->plans->delete($this->by, $a->id);
        $plan = $this->plans->get($this->by, $this->essay->id);
        $this->assertSame(['Finish', 'Draft'], array_map(fn ($p) => $p->title, $plan->parts()));
        $this->assertSame(0, DB::table('activity_items')->where('id', $step->id)->count());
    }

    public function test_the_words_and_marks_are_checked(): void
    {
        foreach ([['', null], ['x', '0'], ['x', '101'], ['x', 'many'], ['x', '12.5'], [str_repeat('a', 201), null]] as [$title, $marks]) {
            $this->assertThrows(fn () => $this->plans->addPart($this->by, $this->essay->id, $title, $marks), Unprocessable::class);
        }
        $this->assertThrows(fn () => $this->plans->addStep($this->by, $this->essay->id, 'x', 'no-such-part'), NotFound::class);
        $this->assertSame(0, DB::table('activity_items')->count());
    }

    public function test_a_starter_adds_its_parts_steps_and_criteria_after_what_is_there_and_makes_nothing_up_about_marks(): void
    {
        $this->plans->addStep($this->by, $this->essay->id, 'Email the tutor');
        $added = $this->plans->applyStarter($this->by, $this->essay->id, 'essay');
        $plan = $this->plans->get($this->by, $this->essay->id);

        $this->assertSame(['Research', 'Plan', 'Draft', 'Finish'], array_map(fn ($p) => $p->title, $plan->parts()));
        $this->assertSame(['Read the brief and the marking criteria', 'Find and read the sources', 'Note the key points'], array_map(fn ($s) => $s->title, $plan->steps($plan->parts()[0]->id)));
        $this->assertSame(['Argument and analysis', 'Use of sources', 'Structure and clarity', 'Referencing and presentation'], array_map(fn ($c) => $c->title, $plan->criteria()));
        $this->assertSame([null], array_unique(array_map(fn ($i) => $i->weight, $plan->items)));
        $this->assertSame(count($plan->items) - 1, $added);
        $this->assertSame(['Email the tutor'], array_map(fn ($s) => $s->title, $plan->steps()));

        foreach (array_keys(PlanStarters::ALL) as $key) {
            $this->assertGreaterThan(0, $this->plans->applyStarter($this->by, $this->essay->id, $key));
        }
        $this->assertThrows(fn () => $this->plans->applyStarter($this->by, $this->essay->id, 'nope'), NotFound::class);
    }

    public function test_a_plan_holds_at_most_200_things(): void
    {
        DB::table('activity_items')->insert(array_map(fn ($i) => [
            'id' => 'bulk-'.$i, 'learner_id' => DB::table('activities')->where('id', $this->essay->id)->value('learner_id'), 'workspace_id' => $this->databases->id,
            'activity_id' => $this->essay->id, 'kind' => 'step', 'parent_id' => null, 'title' => "Step {$i}", 'weight' => null, 'state' => 'todo',
            'position' => $i, 'created_at' => now(), 'updated_at' => now(),
        ], range(1, Plans::MAX_ITEMS)));

        $this->assertThrows(fn () => $this->plans->addStep($this->by, $this->essay->id, 'One more'), Conflict::class);
        $this->assertThrows(fn () => $this->plans->applyStarter($this->by, $this->essay->id, 'essay'), Conflict::class);
    }

    public function test_pace_says_what_is_left_against_the_time_left(): void
    {
        $part = $this->plans->addPart($this->by, $this->essay->id, 'Work');
        foreach (range(1, 9) as $i) {
            $this->plans->addStep($this->by, $this->essay->id, "Step {$i}", $part->id);
        }
        $progress = fn () => $this->plans->get($this->by, $this->essay->id)->progress();
        $essay = fn () => $this->activities->find($this->by, $this->essay->id);

        // Due 09:00 UTC on the 6th: exactly 4 days from now.
        $this->assertSame(['words' => '9 left, about 3 a day', 'tone' => 'ok'], $progress()->pace($essay()));

        Carbon::setTestNow(Carbon::parse('2026-10-05 18:00', 'UTC'));
        $this->assertSame('tight', $progress()->pace($essay())['tone']);
        $this->assertSame('9 left, due in 15 hours', $progress()->pace($essay())['words']);

        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00', 'UTC'));
        $this->assertSame(['words' => '9 left and the deadline has passed', 'tone' => 'late'], $progress()->pace($essay()));

        $this->activities->setStatus($this->by, $this->essay->id, 'done');
        $this->assertNull($progress()->pace($essay()));
    }

    public function test_deleting_the_assignment_or_the_workspace_takes_the_plan_and_only_that_one(): void
    {
        $other = $this->activities->create($this->by, $this->databases->id, ['title' => 'Lab 1', 'kind' => 'lab']);
        $this->plans->addStep($this->by, $this->essay->id, 'Draft');
        $this->plans->addStep($this->by, $other->id, 'Run it');

        $this->activities->delete($this->by, $this->essay->id);
        $this->assertSame(['Run it'], DB::table('activity_items')->pluck('title')->all());

        app(Workspaces::class)->delete($this->by, $this->databases->id);
        $this->assertSame(0, DB::table('activity_items')->count());
    }

    public function test_summaries_give_each_assignments_progress_and_leave_out_those_without_a_plan(): void
    {
        $lab = $this->activities->create($this->by, $this->databases->id, ['title' => 'Lab 1', 'kind' => 'lab']);
        $step = $this->plans->addStep($this->by, $lab->id, 'Run it');
        $this->plans->addStep($this->by, $lab->id, 'Write it up');
        $this->plans->setState($this->by, $step->id, 'done');

        $summaries = $this->plans->summaries($this->by, $this->databases->id);
        $this->assertSame([$lab->id], array_keys($summaries));
        $this->assertSame([1, 2, 50], [$summaries[$lab->id]->done, $summaries[$lab->id]->total, $summaries[$lab->id]->percent]);
    }

    public function test_only_the_owner_can_see_or_change_a_plan(): void
    {
        $step = $this->plans->addStep($this->by, $this->essay->id, 'Draft');
        $bob = $this->principal($this->student());

        $this->assertThrows(fn () => $this->plans->get($bob, $this->essay->id), NotFound::class);
        $this->assertThrows(fn () => $this->plans->addStep($bob, $this->essay->id, 'Mine'), NotFound::class);
        $this->assertThrows(fn () => $this->plans->setState($bob, $step->id, 'done'), NotFound::class);
        $this->assertThrows(fn () => $this->plans->delete($bob, $step->id), NotFound::class);
        $this->assertThrows(fn () => $this->plans->summaries($bob, $this->databases->id), NotFound::class);
    }

    public function test_the_prompt_carries_the_assignment_and_the_brief_and_the_reply_is_read_leniently(): void
    {
        $prompt = app(PlanMaker::class)->prompt($this->by, $this->essay->id, "Write 2000 words on membranes.\nMarked on analysis (40%).");
        foreach (['Databases', 'Cell essay', 'assignment', 'Tuesday 6 October 2026 at 09:00', 'Write 2000 words on membranes.', '<part title='] as $needle) {
            $this->assertStringContainsString($needle, $prompt);
        }
        $this->assertStringNotContainsString('{{', $prompt);
        $this->assertStringNotContainsString('This comment is for people', $prompt);
        $this->assertStringContainsString('hasn\'t shared the brief', app(PlanMaker::class)->prompt($this->by, $this->essay->id, ''));

        $read = PlanMaker::read(<<<'REPLY'
            Here is your plan!

            <part title="Research" marks="20%">
              <step>Read the brief</step>
              <step> Find   5 sources </step>
            </part>
            <part title='Question 1'></part>
            <part title="" marks="5"><step>Orphan</step></part>
            <step>Email the tutor &amp; ask</step>
            <criterion marks="40">Critical analysis</criterion>
            <criterion marks="400">Referencing</criterion>
            <criterion>  </criterion>
            REPLY);
        $this->assertSame([
            'parts' => [['title' => 'Research', 'marks' => 20, 'steps' => ['Read the brief', 'Find 5 sources']], ['title' => 'Question 1', 'marks' => null, 'steps' => []]],
            'steps' => ['Email the tutor & ask'],
            'criteria' => [['title' => 'Critical analysis', 'marks' => 40], ['title' => 'Referencing', 'marks' => null]],
        ], $read);

        $this->assertSame(7, $this->plans->addAll($this->by, $this->essay->id, $read));
        $this->assertSame(['Research', 'Question 1'], array_map(fn ($p) => $p->title, $this->plans->get($this->by, $this->essay->id)->parts()));
        $this->assertSame(['parts' => [], 'steps' => [], 'criteria' => []], PlanMaker::read('Sorry, I can\'t help with that.'));
    }
}
