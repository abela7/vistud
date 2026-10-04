<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\AssignmentBoard;
use App\Livewire\Workspaces\AssignmentPage;
use App\Livewire\Workspaces\AssignmentPlan;
use App\Livewire\Workspaces\Form;
use App\Livewire\Workspaces\SectionPage;
use App\Livewire\Workspaces\Tasks;
use App\Models\User;
use App\Study\Activities;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Plans;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The Assignments section, an assignment's own page and a new one (the owner's review, 2026-10-02). */
class AssignmentScreensTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $databases;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-02 09:00', 'UTC'));
        $this->ada = $this->student();
        $this->databases = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Databases']);
    }

    public function test_the_section_says_how_to_start_then_shows_each_assignment_with_how_long_is_left_and_its_files(): void
    {
        Storage::fake('local');
        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'assignments']))
            ->assertOk()->assertSee('No assignments yet')->assertSee(route('workspaces.assignments.create', $this->databases->id), false);

        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);
        $essay = app(Activities::class)->create($by, $this->databases->id, ['title' => 'ER diagram', 'due_on' => '2026-10-02', 'due_time' => '17:00', 'module_id' => $week1->id]);
        app(Files::class)->upload($by, 'folder', $essay->folderId, $this->temp($this->pdf()), 'Brief.pdf');
        $done = app(Activities::class)->create($by, $this->databases->id, ['title' => 'Lab 1', 'kind' => 'lab']);
        app(Activities::class)->setStatus($by, $done->id, 'done');

        $this->actingAs($this->ada);
        Livewire::test(AssignmentBoard::class, ['workspaceId' => $this->databases->id])
            ->assertSee('ER diagram')->assertSee('8 hours left')->assertSee('Due today, 17:00')->assertSee('Week 1')->assertSee('1 file')
            ->assertSee(route('workspaces.assignments.show', [$this->databases->id, $essay->id]), false)
            ->assertDontSee('Lab 1')
            ->call('show', 'done')->assertSee('Lab 1')->assertDontSee('ER diagram');
    }

    public function test_a_new_assignment_is_made_with_its_name_deadline_and_module_and_its_page_shows_its_files(): void
    {
        Storage::fake('local');
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);
        $this->actingAs($this->ada)->get(route('workspaces.assignments.create', [$this->databases->id, 'module' => $week1->id]))->assertOk()->assertSee('New assignment');

        $page = Livewire::test(AssignmentPage::class, ['workspaceId' => $this->databases->id, 'inModule' => $week1->id])
            ->assertSet('moduleId', $week1->id)
            ->call('save')->assertHasErrors(['title'])
            ->set('title', 'Coursework 1')->set('dueOn', '2026-10-09')->set('dueTime', '23:59')
            ->call('save')->assertReturned(true)->assertHasNoErrors()->assertSee('“Coursework 1” is added.');

        $made = app(Activities::class)->list($by, $this->databases->id)[0];
        $page->assertSet('activityId', $made->id)->assertSee('7 days left')->assertSee('Due 9 Oct, 23:59');
        $folder = app(Folders::class)->find($by, $made->folderId);
        $this->assertSame(['Coursework 1', $week1->id], [$folder->name, $folder->moduleId]);
        $this->assertSame(['folder', $folder->id], $page->instance()->filesFolder());

        // Its files and notes, each opening on its own page.
        $brief = app(Files::class)->upload($by, 'folder', $folder->id, $this->temp($this->pdf()), 'Brief.pdf');
        $page->call('uploadsFinished', 1, 0)->assertSee('1 file added.')->assertSee('Brief.pdf')
            ->assertSee(route('workspaces.files.show', [$this->databases->id, $brief->id]), false)
            ->assertSee(route('workspaces.folders.show', [$this->databases->id, $folder->id]), false);
    }

    public function test_its_page_saves_its_details_and_where_the_student_is_and_deleting_it_keeps_its_files(): void
    {
        $by = $this->principal($this->ada);
        $essay = app(Activities::class)->create($by, $this->databases->id, ['title' => 'ER diagram', 'due_on' => '2026-10-01']);
        $this->actingAs($this->ada)->get(route('workspaces.assignments.show', [$this->databases->id, $essay->id]))
            ->assertOk()->assertSee('ER diagram')->assertSee('Was due 1 Oct');

        $page = Livewire::test(AssignmentPage::class, ['workspaceId' => $this->databases->id, 'activityId' => $essay->id])
            ->set('title', 'ER diagram (group)')->set('dueOn', '2026-10-05')->call('save')->assertReturned(false)->assertSee('Saved.')
            ->set('status', 'doing')->assertSee('In progress.');
        $this->assertSame(['ER diagram (group)', '2026-10-05', 'doing'], [
            app(Activities::class)->find($by, $essay->id)->title, app(Activities::class)->find($by, $essay->id)->dueOn, app(Activities::class)->find($by, $essay->id)->status,
        ]);
        $this->assertSame('ER diagram (group)', app(Folders::class)->find($by, $essay->folderId)->name);

        $page->call('delete')->assertRedirect(route('workspaces.show', [$this->databases->id, 'assignments']));
        $this->assertSame('“ER diagram (group)” is deleted. Its folder “ER diagram (group)” and its files stay where they were.', session('workspace-notice'));
        $this->assertSame('ER diagram (group)', app(Folders::class)->find($by, $essay->folderId)->name);
    }

    public function test_only_the_owner_in_that_workspace_sees_it_and_the_browser_cant_change_which_one(): void
    {
        $by = $this->principal($this->ada);
        $essay = app(Activities::class)->create($by, $this->databases->id, ['title' => 'ER diagram']);
        $maths = app(Workspaces::class)->create($by, ['name' => 'Maths']);
        $this->actingAs($this->ada)->get(route('workspaces.assignments.show', [$maths->id, $essay->id]))->assertNotFound();
        $this->actingAs($this->student())->get(route('workspaces.assignments.show', [$this->databases->id, $essay->id]))->assertNotFound();

        $this->actingAs($this->ada);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(AssignmentPage::class, ['workspaceId' => $this->databases->id, 'activityId' => $essay->id])->set('activityId', 'another');
    }

    public function test_the_overview_card_takes_a_time_and_opens_each_assignments_page(): void
    {
        $this->actingAs($this->ada);
        Livewire::test(Tasks::class, ['workspaceId' => $this->databases->id])
            ->call('newTask')->set('title', 'Quiz 2')->set('kind', 'quiz')->set('dueOn', '2026-10-03')->set('dueTime', '10:00')->call('save')
            ->assertSee('Due tomorrow, 10:00')->assertSee(route('workspaces.show', [$this->databases->id, 'assignments']), false);
        $quiz = app(Activities::class)->list($this->principal($this->ada), $this->databases->id)[0];
        $this->assertSame('10:00', $quiz->dueTime);
    }

    public function test_project_tools_are_off_for_a_new_course_and_none_of_them_is_shown(): void
    {
        $by = $this->principal($this->ada);
        $this->actingAs($this->ada);
        $this->assertFalse($this->databases->projectTools);
        $project = app(Activities::class)->create($by, $this->databases->id, ['title' => 'Group project', 'kind' => 'project', 'due_on' => '2026-10-20', 'due_time' => '09:00']);
        $plans = app(Plans::class);
        $part = $plans->addPart($by, $project->id, 'Research');
        // One task on the plan itself and one inside the section, both with a priority and a label.
        $step = $plans->addStep($by, $project->id, 'Write the report');
        $inside = $plans->addStep($by, $project->id, 'Read the brief', $part->id);
        foreach ([$step, $inside] as $item) {
            $plans->update($by, $item->id, ['priority' => 'high', 'labels' => ['report'], 'due_on' => '2026-10-01']);
        }
        $plans->setState($by, $step->id, 'stuck');
        $plans->addMilestone($by, $project->id, 'Proposal agreed', '2026-10-10');
        $plans->addMember($by, $project->id, 'Sara Bekele');

        // The plan: the sections and tasks with their dates, and nothing of milestones, a team, priorities, labels or health.
        $plan = Livewire::test(AssignmentPlan::class, ['workspaceId' => $this->databases->id, 'activityId' => $project->id])
            ->assertSee('Research')->assertSee('Marking criteria')
            ->assertDontSee('Milestones')->assertDontSee('Proposal agreed')->assertDontSee('Team')->assertDontSee('Sara Bekele')
            ->assertDontSee('At risk')->assertDontSee('Off track')->assertDontSee('On track');
        $plan->call('startEdit', $step->id)->assertSee('Notes')->assertDontSee('Priority')->assertDontSee('Labels')->assertDontSee('Sara Bekele');
        // Saving the form without those fields leaves them as they were.
        $plan->set('editTitle', 'Write the whole report')->call('saveEdit')->assertHasNoErrors();
        $saved = $plans->get($by, $project->id)->item($step->id);
        $this->assertSame(['Write the whole report', 'high', ['report']], [$saved->title, $saved->priority, $saved->labels]);

        // The assignment's page keeps to its title, deadline, state and plan; the cards and Coming up don't judge how it is going.
        Livewire::test(AssignmentPage::class, ['workspaceId' => $this->databases->id, 'activityId' => $project->id])->assertSee('Group project')->assertSee('Where you are')->assertDontSee('At risk')->assertDontSee('Off track');
        Livewire::test(AssignmentBoard::class, ['workspaceId' => $this->databases->id])->assertSee('Group project')->assertDontSee('At risk')->assertDontSee('Off track');
        Livewire::test(Tasks::class, ['workspaceId' => $this->databases->id])->assertSee('Group project')->assertDontSee('At risk')->assertDontSee('Off track');
        $this->actingAs($this->ada);
        Livewire::test(SectionPage::class, ['workspaceId' => $this->databases->id, 'activityId' => $project->id, 'sectionId' => $part->id])->assertDontSee('High')->assertDontSee('report')->assertDontSee('Priority');
    }

    public function test_turning_project_tools_on_shows_them_and_off_hides_them_again_without_deleting_any(): void
    {
        $by = $this->principal($this->ada);
        $project = app(Activities::class)->create($by, $this->databases->id, ['title' => 'Group project', 'kind' => 'project', 'due_on' => '2026-10-20', 'due_time' => '09:00']);
        $plans = app(Plans::class);
        $step = $plans->addStep($by, $project->id, 'Write the report');
        $plans->update($by, $step->id, ['priority' => 'high', 'labels' => ['report']]);
        $plans->addMilestone($by, $project->id, 'Proposal agreed', '2026-10-10');

        // The course's dialog has the switch; saving it changes the course and nothing else about it.
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        Livewire::test(Form::class, ['workspaceId' => $this->databases->id])->assertSee('Project tools for assignments')->assertSet('projectTools', false)
            ->set('projectTools', true)->call('save')->assertHasNoErrors();
        $this->assertTrue(app(Workspaces::class)->find($by, $this->databases->id)->projectTools);
        $this->assertSame('Databases', app(Workspaces::class)->find($by, $this->databases->id)->name);

        Livewire::test(AssignmentPlan::class, ['workspaceId' => $this->databases->id, 'activityId' => $project->id])
            ->assertSee('Milestones')->assertSee('Proposal agreed')->assertSee('High')->assertSee('report')->assertSee('Team')
            ->call('startEdit', $step->id)->assertSee('Priority')->assertSee('Labels');
        Livewire::test(AssignmentPage::class, ['workspaceId' => $this->databases->id, 'activityId' => $project->id])->assertSee('On track');

        Livewire::test(Form::class, ['workspaceId' => $this->databases->id])->assertSet('projectTools', true)->set('projectTools', false)->call('save');
        $this->assertFalse(app(Workspaces::class)->find($by, $this->databases->id)->projectTools);
        $this->assertSame(['high', ['report']], [$plans->get($by, $project->id)->item($step->id)->priority, $plans->get($by, $project->id)->item($step->id)->labels]);
        $this->assertCount(1, $plans->get($by, $project->id)->milestones());
    }

    public function test_a_plan_read_from_an_ai_reply_brings_no_milestones_to_a_course_that_does_not_show_them(): void
    {
        $by = $this->principal($this->ada);
        $this->actingAs($this->ada);
        $essay = app(Activities::class)->create($by, $this->databases->id, ['title' => 'Cell essay', 'due_on' => '2026-10-20', 'due_time' => '09:00']);
        $reply = '<part title="Research" marks="30"><step>Read chapter 3</step></part><milestone date="2026-10-12">Proposal signed off</milestone>';

        Livewire::test(AssignmentPlan::class, ['workspaceId' => $this->databases->id, 'activityId' => $essay->id])
            ->call('toggleAi')->set('reply', $reply)->call('readReply')->assertSet('reading', true)->assertSee('Research')->assertDontSee('Proposal signed off')
            ->call('addRead');
        $this->assertSame([], app(Plans::class)->get($by, $essay->id)->milestones());
        $this->assertCount(1, app(Plans::class)->get($by, $essay->id)->parts());
    }
}
