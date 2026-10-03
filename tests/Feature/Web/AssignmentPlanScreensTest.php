<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\AssignmentBoard;
use App\Livewire\Workspaces\AssignmentPage;
use App\Livewire\Workspaces\AssignmentPlan;
use App\Livewire\Workspaces\Tasks;
use App\Models\User;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\PlanMaker;
use App\Study\Plans;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** An assignment's plan on its page, and its progress on the cards and the Overview (the owner's review, 2026-10-02). */
class AssignmentPlanScreensTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $databases;

    private ActivityDetails $essay;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00', 'UTC'));
        $this->ada = $this->student();
        $by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $this->essay = app(Activities::class)->create($by, $this->databases->id, ['title' => 'Cell essay', 'due_on' => '2026-10-06', 'due_time' => '09:00']);
        $this->actingAs($this->ada);
    }

    private function plan()
    {
        return Livewire::test(AssignmentPlan::class, ['workspaceId' => $this->databases->id, 'activityId' => $this->essay->id]);
    }

    public function test_an_empty_plan_is_calm_the_pre_made_ones_wait_to_be_asked_for_and_the_page_shows_the_plan_only_once_it_exists(): void
    {
        $this->plan()->assertSee('Split the work into sections')->assertSee('Plan with an AI')->assertSee('New section')->assertSee('Add a pre-made plan')->assertDontSee('Clear the plan')
            ->assertDontSee('Essay or report')->assertDontSee('Also track')->assertDontSee('Marking criteria')
            ->call('toggleStarters')->assertSet('showStarters', true)->assertSee('Essay or report')->assertSee('Problem set')->assertSee('Group project')
            ->call('toggleStarters')->assertDontSee('Essay or report');
        $this->get(route('workspaces.assignments.show', [$this->databases->id, $this->essay->id]))->assertOk()->assertSee('Split the work into sections')->assertSee('Edit details');
        $this->get(route('workspaces.assignments.create', $this->databases->id))->assertOk()->assertDontSee('Split the work into sections')->assertDontSee('Edit details');
    }

    public function test_the_pre_made_plans_the_ai_and_clearing_take_turns_and_clearing_asks_first(): void
    {
        $plans = app(Plans::class);
        $by = $this->principal($this->ada);
        $team = $plans->addMember($by, $this->essay->id, 'Sara');

        $component = $this->plan()->call('toggleStarters')->assertSet('showStarters', true)->call('toggleAi')->assertSet('aiOpen', true)->assertSet('showStarters', false)
            ->call('toggleStarters')->assertSet('aiOpen', false)->call('useStarter', 'essay')->assertSet('showStarters', false)->assertSee('Argument and analysis');
        $this->assertNotSame([], $plans->get($by, $this->essay->id)->items);

        // Clearing asks first, and keeping it changes nothing.
        $component->assertDontSee('Remove everything in the plan?')->call('askClear')->assertSet('confirmClear', true)->assertSee('Remove everything in the plan?')
            ->call('cancelClear')->assertSet('confirmClear', false)->assertDontSee('Remove everything in the plan?');
        $this->assertNotSame([], $plans->get($by, $this->essay->id)->items);

        // Clearing takes the parts, steps and criteria, and leaves the team, and the plan starts over.
        $component->call('askClear')->call('clearPlan')->assertSet('confirmClear', false)->assertSee('The plan is cleared.')->assertSee('Split the work into sections')->assertDispatched('plan-changed');
        $plan = $plans->get($by, $this->essay->id);
        $this->assertSame([[], [$team->id]], [$plan->items, array_map(fn ($m) => $m->id, $plan->members)]);
        $component->call('clearPlan')->assertSee('The plan was already empty.');
    }

    public function test_adding_is_quiet_until_asked_and_milestones_the_team_and_criteria_wait_to_be_wanted(): void
    {
        $plans = app(Plans::class);
        $by = $this->principal($this->ada);
        $part = $plans->addPart($by, $this->essay->id, 'Research');

        $component = $this->plan()->assertSee('Research')->assertSee('Add a step')->assertSee('New section')->assertSee('Clear the plan')->assertSee('Also track')->assertSee('Milestones')->assertSee('Team')->assertSee('Marking criteria')
            ->assertDontSee('Add a milestone')->assertDontSee('Add a person')->assertDontSee('Add a criterion')->assertDontSee('Days to reach')->assertDontSee('Who shares the work');
        $component->call('$set', 'showMilestones', true)->assertSee('Add a milestone')->assertDontSee('Add a person');
        $component->call('$set', 'showTeam', true)->assertSee('Add a person');
        $component->call('$set', 'showCriteria', true)->assertSee('Add a criterion')->assertDontSee('Also track');
        $this->assertSame('Research', $plans->get($by, $this->essay->id)->item($part->id)->title);
    }

    public function test_sections_are_added_with_their_weight_and_the_weights_are_checked(): void
    {
        $plans = app(Plans::class);
        $by = $this->principal($this->ada);

        $component = $this->plan()->set('partText', 'Research')->set('partMarks', '30')->call('addPart')->assertHasNoErrors()->assertSet('partMarks', '')
            ->assertSee('30%')->assertSee('Weights add up to 30%: 70% is in no section')->assertSee('0 of 30%')->assertSee('70% left');
        $component->set('partText', 'Writing')->call('addPart')->assertSee('30% given · 70% shared by the section without a weight')->assertSee('0 of 70%');
        $writing = $plans->get($by, $this->essay->id)->parts()[1];

        // The weight is changed where it is shown; nonsense says so on that section; empty takes it away.
        $component->call('setWeight', $writing->id, '70')->assertSee('Weights add up to 100%')->assertDispatched('plan-changed');
        $this->assertSame(70, $plans->get($by, $this->essay->id)->item($writing->id)->weight);
        $component->call('setWeight', $writing->id, '500')->assertHasErrors(["weight.{$writing->id}"])->assertSee('Enter a number from 1 to 100');
        $component->call('setWeight', $writing->id, '90')->assertSee('Weights add up to 120%: 20% too many');
        $component->call('setWeight', $writing->id, '')->assertSee('shared by the section without a weight');
        $this->assertNull($plans->get($by, $this->essay->id)->item($writing->id)->weight);

        // Ticking a section with no steps earns its weight.
        $research = $plans->get($by, $this->essay->id)->parts()[0];
        $component->call('setState', $research->id, 'done')->assertSee('30%')->assertSee('30 of 30%');

        // Without weights, it counts the steps and says so.
        $component->call('setWeight', $research->id, '');
        $component->assertSee('Counting steps');
    }

    public function test_an_ais_prompt_is_strong_and_carries_the_time_left(): void
    {
        $prompt = app(PlanMaker::class)->prompt($this->principal($this->ada), $this->essay->id, 'Write 1500 words on osmosis. Marked on analysis (50%).');
        foreach (['Today: Friday 2 October 2026', 'Time left: 4 days', 'Deadline: Tuesday 6 October 2026 at 09:00', 'Write 1500 words on osmosis.', 'ONE code block', 'Start each with a verb', 'specific to THIS brief', 'Assumptions'] as $needle) {
            $this->assertStringContainsString($needle, $prompt);
        }
        $this->assertStringNotContainsString('{{', $prompt);
    }

    public function test_a_starter_makes_a_plan_that_is_ticked_checked_and_changed_by_hand(): void
    {
        $plans = app(Plans::class);
        $by = $this->principal($this->ada);

        $component = $this->plan()->call('useStarter', 'essay')->assertSee('Added the “Essay or report” plan.')->assertSee('Research')->assertSee('Find and read the sources')
            ->assertSee('Argument and analysis')->assertSee('0%')->assertDispatched('plan-changed');
        $plan = $plans->get($by, $this->essay->id);

        // Ticking a step: progress, and the assignment starts.
        $component->call('setState', $plan->steps($plan->parts()[0]->id)[0]->id, 'done')->assertSee('1 of 12 done');
        $this->assertSame('doing', app(Activities::class)->find($by, $this->essay->id)->status);

        // A criterion is checked, not ticked.
        $component->call('setState', $plan->criteria()[0]->id, 'partly');
        $this->assertSame('partly', $plans->get($by, $this->essay->id)->criteria()[0]->state);

        // Added by hand, with marks, and the progress counts by them.
        $component->set('partText', 'Reflection')->set('partMarks', '10')->call('addPart')->assertHasNoErrors()->assertSet('partText', '')->assertSee('Reflection')->assertSee('10%');
        $component->set('stepText.loose', 'Email the tutor')->call('addStep')->assertSee('Email the tutor')->assertSet('stepText', []);
        $component->set('criterionText', 'Originality')->set('criterionMarks', '15')->call('addCriterion')->assertSee('Originality');

        // Renamed, moved, deleted.
        $reflection = $plans->get($by, $this->essay->id)->parts()[4];
        $component->call('startEdit', $reflection->id)->assertSet('editTitle', 'Reflection')->assertSet('editMarks', '10')
            ->set('editTitle', 'Final reflection')->set('editMarks', '')->call('saveEdit')->assertSet('editing', null)->assertSee('Final reflection');
        $component->call('move', $reflection->id, 'up')->call('remove', $reflection->id)->assertDontSee('Final reflection');
    }

    public function test_bad_words_and_marks_say_what_is_wrong_where_it_was_typed(): void
    {
        $this->plan()
            ->call('addPart')->assertHasErrors(['partText'])
            ->set('partText', 'Part')->set('partMarks', '500')->call('addPart')->assertHasErrors(['partMarks'])
            ->call('addStep')->assertHasErrors(['stepText.loose'])
            ->call('addCriterion')->assertHasErrors(['criterionText']);
    }

    public function test_a_finished_plan_offers_to_mark_the_assignment_done(): void
    {
        $plans = app(Plans::class);
        $by = $this->principal($this->ada);
        $step = $plans->addStep($by, $this->essay->id, 'Write it');

        $component = $this->plan()->assertDontSee('Everything in your plan is ticked.')
            ->call('setState', $step->id, 'done')->assertSee('Everything in your plan is ticked.')->assertSee('100%');
        $component->call('markDone')->assertSee('Done. Well done!');
        $this->assertSame('done', app(Activities::class)->find($by, $this->essay->id)->status);
    }

    public function test_an_ais_reply_is_read_looked_over_and_added(): void
    {
        $by = $this->principal($this->ada);
        $component = $this->plan()->call('toggleAi')->assertSet('aiOpen', true)
            ->set('brief', 'Write 2000 words on membranes.')->assertSee('Write 2000 words on membranes.')->assertSee('Cell essay')
            ->call('readReply')->assertHasErrors(['reply']);

        $component->set('reply', "Sure!\n<part title=\"Research\" marks=\"30\"><step>Find 5 sources</step></part><step>Email the tutor</step><criterion marks=\"40\">Analysis</criterion>")
            ->call('readReply')->assertHasNoErrors()->assertSet('reading', true)->assertSee('Look it over, then add it')->assertSee('Find 5 sources')->assertSee('Analysis');
        $this->assertSame([], app(Plans::class)->get($by, $this->essay->id)->items);

        $component->call('addRead')->assertSet('aiOpen', false)->assertSet('reply', '')->assertSee('4 things added to your plan.');
        $plan = app(Plans::class)->get($by, $this->essay->id);
        $this->assertSame([['Research', 30]], array_map(fn ($p) => [$p->title, $p->weight], $plan->parts()));
        $this->assertSame(['Email the tutor'], array_map(fn ($s) => $s->title, $plan->steps()));
    }

    public function test_the_page_follows_the_plan_and_the_cards_and_overview_show_how_far_it_has_got(): void
    {
        $plans = app(Plans::class);
        $by = $this->principal($this->ada);
        $first = $plans->addStep($by, $this->essay->id, 'Draft');
        $plans->addStep($by, $this->essay->id, 'Proofread');

        $page = Livewire::test(AssignmentPage::class, ['workspaceId' => $this->databases->id, 'activityId' => $this->essay->id])->assertSet('status', 'todo');
        $plans->setState($by, $first->id, 'done');
        $page->dispatch('plan-changed')->assertSet('status', 'doing');

        Livewire::test(AssignmentBoard::class, ['workspaceId' => $this->databases->id])->assertSee('50% · 1 of 2 done')->assertSee('1 left, about 1 a day');
        Livewire::test(Tasks::class, ['workspaceId' => $this->databases->id])->assertSee('50% of the plan');
    }

    public function test_the_browser_cannot_change_which_assignment_the_plan_is_for(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->plan()->set('activityId', 'another');
    }
}
