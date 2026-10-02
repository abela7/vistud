<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\AssignmentBoard;
use App\Livewire\Workspaces\AssignmentPage;
use App\Livewire\Workspaces\AssignmentPlan;
use App\Livewire\Workspaces\Tasks;
use App\Models\User;
use App\Study\Activities;
use App\Study\ActivityDetails;
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

    public function test_an_empty_plan_offers_starters_and_an_ai_and_the_page_shows_the_plan_only_once_it_exists(): void
    {
        $this->plan()->assertSee('How do you want to start?')->assertSee('Essay or report')->assertSee('Problem set')->assertSee('Plan it with an AI');
        $this->get(route('workspaces.assignments.show', [$this->databases->id, $this->essay->id]))->assertOk()->assertSee('Marking criteria');
        $this->get(route('workspaces.assignments.create', $this->databases->id))->assertOk()->assertDontSee('Marking criteria');
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
        $page->dispatch('plan-changed')->assertSet('status', 'doing')->assertSee('50% of the plan')->assertSee('1 left, about 1 a day');

        Livewire::test(AssignmentBoard::class, ['workspaceId' => $this->databases->id])->assertSee('50% · 1 of 2 done')->assertSee('1 left, about 1 a day');
        Livewire::test(Tasks::class, ['workspaceId' => $this->databases->id])->assertSee('50% of the plan');
    }

    public function test_the_browser_cannot_change_which_assignment_the_plan_is_for(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->plan()->set('activityId', 'another');
    }
}
