<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\AssignmentBoard;
use App\Livewire\Workspaces\AssignmentPage;
use App\Livewire\Workspaces\Tasks;
use App\Models\User;
use App\Study\Activities;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Modules;
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
}
