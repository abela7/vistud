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
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A project's plan on its page: states, details, steps under steps, milestones, the team and how it is going. */
class ProjectPlanScreensTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $databases;

    private ActivityDetails $project;

    private Plans $plans;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00', 'UTC'));
        $this->ada = $this->student();
        $by = $this->principal($this->ada);
        // A course with project tools: without them none of this is shown (AssignmentScreensTest).
        $this->databases = app(Workspaces::class)->create($by, ['name' => 'Databases', 'project_tools' => true]);
        $this->project = app(Activities::class)->create($by, $this->databases->id, ['title' => 'Group project', 'kind' => 'project', 'due_on' => '2026-10-20', 'due_time' => '09:00']);
        $this->plans = app(Plans::class);
        $this->actingAs($this->ada);
    }

    private function plan()
    {
        return Livewire::test(AssignmentPlan::class, ['workspaceId' => $this->databases->id, 'activityId' => $this->project->id]);
    }

    private function by()
    {
        return $this->principal($this->ada);
    }

    public function test_a_project_offers_its_own_pre_made_plans_first_when_asked(): void
    {
        $this->plan()->assertDontSee('Group project')->call('toggleStarters')->assertSeeInOrder(['Project', 'Group project', 'Essay or report']);

        $this->plan()->call('useStarter', 'group')->assertSee('Team set-up')->assertSee('Roles agreed')->assertSee('Teamwork and contribution')->assertSee('Also track');
        $this->assertSame(['Roles agreed', 'First draft together', 'Final hand-in'], array_map(fn ($m) => $m->title, $this->plans->get($this->by(), $this->project->id)->milestones()));
    }

    public function test_milestones_and_the_team_are_not_forced_on_a_project_either(): void
    {
        $this->plan()->assertDontSee('Add a milestone')->assertDontSee('Add a person')->call('useStarter', 'project')->assertSee('Proposal agreed')->assertSee('Add a milestone')->assertDontSee('Add a person')->assertSee('Team');
    }

    public function test_a_step_is_started_stuck_and_done_and_says_where_it_is(): void
    {
        $step = $this->plans->addStep($this->by(), $this->project->id, 'Write the report');
        $component = $this->plan()->call('setState', $step->id, 'doing')->assertSee('In progress')->assertSee('Mark done');
        $this->assertSame('doing', app(Activities::class)->find($this->by(), $this->project->id)->status);

        $component->call('setState', $step->id, 'stuck')->assertSee('Stuck')->assertSee('1 task is stuck')->assertSee('At risk');
        $component->call('setState', $step->id, 'done')->assertDontSee('Stuck')->assertDontSee('At risk')->assertSee('100%');
        $component->call('setState', $step->id, 'someday');
        $this->assertSame('done', $this->plans->get($this->by(), $this->project->id)->item($step->id)->state);
    }

    public function test_steps_go_under_steps_three_levels_deep_and_no_more(): void
    {
        $one = $this->plans->addStep($this->by(), $this->project->id, 'Backend');
        $component = $this->plan()->call('toggleSub', $one->id)->assertSet('adding', $one->id)->assertSee('Done adding');
        $component->set("stepText.{$one->id}", 'API')->call('addStep', $one->id)->assertHasNoErrors()->assertSee('API')->assertSee('0/1');

        $plan = $this->plans->get($this->by(), $this->project->id);
        $two = $plan->steps($one->id)[0];
        $three = $this->plans->addStep($this->by(), $this->project->id, 'Routes', $two->id);
        $component->set("stepText.{$three->id}", 'Too deep')->call('addStep', $three->id)->assertSee('Steps go at most 3 levels deep.');
        $this->assertSame([], $this->plans->get($this->by(), $this->project->id)->steps($three->id));

        // Closing the box, and what a step that holds steps shows: how far they are.
        $component->call('toggleSub', $one->id)->assertSet('adding', null);
        $this->plans->setState($this->by(), $three->id, 'done');
        $component->call('$refresh')->assertSee('1/1');
    }

    public function test_an_item_is_given_dates_a_priority_labels_notes_and_a_person(): void
    {
        $sara = $this->plans->addMember($this->by(), $this->project->id, 'Sara Bekele');
        $step = $this->plans->addStep($this->by(), $this->project->id, 'Write the report');

        $component = $this->plan()->call('startEdit', $step->id)->assertSet('editTitle', 'Write the report')->assertSee('Priority')->assertSee('Labels')->assertSee('Sara Bekele');
        $component->set('editStart', '2026-10-05')->set('editDue', '2026-10-10')->set('editPriority', 'high')->set('editLabels', 'report, writing')->set('editNotes', 'Ask Sara for the figures.')->set('editMember', $sara->id)
            ->call('saveEdit')->assertHasNoErrors()->assertSet('editing', null)
            ->assertSee('5 Oct – 10 Oct')->assertSee('High')->assertSee('Sara Bekele')->assertSee('writing')->assertSee('Ask Sara for the figures.');

        $saved = $this->plans->get($this->by(), $this->project->id)->item($step->id);
        $this->assertSame(['2026-10-05', '2026-10-10', 'high', ['report', 'writing'], $sara->id], [$saved->startOn, $saved->dueOn, $saved->priority, $saved->labels, $saved->memberId]);

        // A wrong date says so by the field; a part has marks, a criterion has no dates.
        $component->call('startEdit', $step->id)->set('editDue', '2026-10-01')->call('saveEdit')->assertHasErrors(['editDue'])->assertSet('editing', $step->id)
            ->set('editDue', '2026-10-10')->set('editPriority', 'whenever')->call('saveEdit')->assertHasErrors(['editPriority']);
        $criterion = $this->plans->addCriterion($this->by(), $this->project->id, 'Quality');
        $this->plan()->call('startEdit', $criterion->id)->assertDontSee('Priority')->assertSee('Marks %');
    }

    public function test_what_is_past_its_day_is_overdue_and_a_missed_milestone_says_so(): void
    {
        $step = $this->plans->addStep($this->by(), $this->project->id, 'Draft');
        $this->plans->update($this->by(), $step->id, ['due_on' => '2026-10-01']);
        $milestone = $this->plans->addMilestone($this->by(), $this->project->id, 'Proposal agreed', '2026-10-01');

        $component = $this->plan()->assertSee('Overdue · Due 1 Oct')->assertSee('Thu 1 Oct · Missed yesterday')->assertSee('1 thing is overdue')->assertSee('A milestone was missed');
        $component->call('setState', $milestone->id, 'achieved')->assertDontSee('Missed')->assertDontSee('A milestone was missed');
        $this->assertSame('achieved', $this->plans->get($this->by(), $this->project->id)->item($milestone->id)->state);
    }

    public function test_milestones_are_added_ticked_changed_and_deleted(): void
    {
        $component = $this->plan()->call('addMilestone')->assertHasErrors(['milestoneText'])
            ->set('milestoneText', 'First draft done')->set('milestoneDate', '2026-10-12')->call('addMilestone')->assertHasNoErrors()->assertSet('milestoneText', '')->assertSee('First draft done')->assertSee('Mon 12 Oct · In 10 days')->assertSee('0 of 1 reached · next: First draft done');
        $milestone = $this->plans->get($this->by(), $this->project->id)->milestones()[0];

        $component->set('milestoneText', 'x')->set('milestoneDate', '2026-02-30')->call('addMilestone')->assertHasErrors(['milestoneDate']);
        $component->call('setState', $milestone->id, 'achieved')->assertSee('1 of 1 reached')->assertSee('Reached');
        $component->call('startEdit', $milestone->id)->assertSee('Day')->assertDontSee('Priority')->set('editTitle', 'Draft in')->set('editDue', '2026-10-14')->call('saveEdit')->assertSee('Draft in')->assertSee('14 Oct');
        $component->call('remove', $milestone->id)->assertDontSee('Draft in');
    }

    public function test_the_team_is_added_renamed_and_shares_the_work_out(): void
    {
        $component = $this->plan()->call('addMember')->assertHasErrors(['memberName'])
            ->set('memberName', 'Abel')->set('memberMe', true)->call('addMember')->assertHasNoErrors()->assertSee('Abel')->assertSee('You')->assertSee('Nothing yet')
            ->assertSet('memberName', '')->assertSet('memberMe', false);
        $component->set('memberName', 'Sara')->call('addMember')->assertSee('Sara');

        [$abel, $sara] = $this->plans->get($this->by(), $this->project->id)->members;
        $this->assertSame([true, false], [$abel->me, $sara->me]);

        $one = $this->plans->addStep($this->by(), $this->project->id, 'Slides');
        $two = $this->plans->addStep($this->by(), $this->project->id, 'Demo');
        $this->plans->addStep($this->by(), $this->project->id, 'Report');
        $this->plans->update($this->by(), $one->id, ['member_id' => $sara->id]);
        $this->plans->update($this->by(), $two->id, ['member_id' => $sara->id]);
        $this->plans->setState($this->by(), $one->id, 'done');
        $component->call('$refresh')->assertSee('1 of 2 done')->assertSee('1 thing has nobody yet.');

        $component->call('toggleMe', $sara->id, true)->call('startRename', $sara->id)->assertSet('memberRename', 'Sara')->set('memberRename', 'Sara Bekele')->call('saveRename')->assertSet('renamingMember', null)->assertSee('Sara Bekele');
        $this->assertSame([false, true], array_map(fn ($m) => $m->me, $this->plans->get($this->by(), $this->project->id)->members));

        $component->call('startRename', $sara->id)->set('memberRename', '')->call('saveRename')->assertHasErrors(['memberRename'])->call('cancelRename');
        $component->call('removeMember', $sara->id)->assertDontSee('Sara Bekele')->assertSee('3 things have nobody yet.');
        $this->assertNull($this->plans->get($this->by(), $this->project->id)->item($one->id)->memberId);
    }

    public function test_how_the_work_is_going_shows_on_the_page_the_cards_and_the_overview(): void
    {
        $step = $this->plans->addStep($this->by(), $this->project->id, 'Build it');
        $this->plans->addStep($this->by(), $this->project->id, 'Test it');
        $this->plan()->assertSee('On track');
        $page = Livewire::test(AssignmentPage::class, ['workspaceId' => $this->databases->id, 'activityId' => $this->project->id])->assertDontSee('At risk');
        $board = Livewire::test(AssignmentBoard::class, ['workspaceId' => $this->databases->id])->assertDontSee('At risk');

        $this->plans->setState($this->by(), $step->id, 'stuck');
        $page->dispatch('plan-changed')->assertSee('At risk');
        Livewire::test(AssignmentBoard::class, ['workspaceId' => $this->databases->id])->assertSee('At risk');
        Livewire::test(Tasks::class, ['workspaceId' => $this->databases->id])->assertSee('At risk');
        $board->assertDontSee('Off track');
    }
}
