<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\SectionForm;
use App\Livewire\Workspaces\SectionPage;
use App\Models\User;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Notes;
use App\Study\Plans;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A section of an assignment's plan on a page of its own, and the page that makes or changes one (the owner's review, 2026-10-04). */
class SectionScreensTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

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

    private function page(string $sectionId)
    {
        return Livewire::test(SectionPage::class, ['workspaceId' => $this->databases->id, 'activityId' => $this->essay->id, 'sectionId' => $sectionId]);
    }

    private function form(?string $sectionId = null)
    {
        return Livewire::test(SectionForm::class, ['workspaceId' => $this->databases->id, 'activityId' => $this->essay->id, 'sectionId' => $sectionId]);
    }

    private function route(string $name, ?string $sectionId = null): string
    {
        return route($name, array_filter([$this->databases->id, $this->essay->id, $sectionId]));
    }

    public function test_a_section_is_made_on_its_own_page_with_its_weight_and_then_opens(): void
    {
        $plans = app(Plans::class);
        $by = $this->principal($this->ada);
        $this->get($this->route('workspaces.assignments.sections.create'))->assertOk()->assertSee('New section')->assertSee('100% is left for this section');

        $form = $this->form()->call('save')->assertHasErrors(['name'])
            ->set('name', 'Research')->set('weight', '500')->call('save')->assertHasErrors(['weight'])->assertSee('Enter a number from 1 to 100');
        $form->set('weight', '30')->set('dueOn', '2026-10-05')->set('description', 'Find what the brief asks for.')->call('save')->assertHasNoErrors();
        $research = $plans->get($by, $this->essay->id)->parts()[0];
        $form->assertRedirect($this->route('workspaces.assignments.sections.show', $research->id));
        $this->assertSame(['Research', 30, '2026-10-05', 'Find what the brief asks for.'], [$research->title, $research->weight, $research->dueOn, $research->notes]);

        // The next one sees what is left.
        $this->form()->assertSee('70% is left for this section');

        // Changing it: the form starts with what it is, and its own weight counts as left.
        $this->get($this->route('workspaces.assignments.sections.edit', $research->id))->assertOk()->assertSee('Edit section')->assertSee('Research');
        $this->form($research->id)->assertSet('name', 'Research')->assertSet('weight', '30')->assertSee('100% is left for this section')
            ->set('name', 'Reading')->set('weight', '')->call('save')->assertHasNoErrors()->assertRedirect($this->route('workspaces.assignments.sections.show', $research->id));
        $this->assertSame(['Reading', null], [$plans->get($by, $this->essay->id)->item($research->id)->title, $plans->get($by, $this->essay->id)->item($research->id)->weight]);
    }

    public function test_a_sections_page_shows_how_far_it_is_and_its_sub_tasks_move_its_tasks_which_move_it(): void
    {
        $plans = app(Plans::class);
        $by = $this->principal($this->ada);
        $research = $plans->addPart($by, $this->essay->id, 'Research', 40);
        $sources = $plans->addStep($by, $this->essay->id, 'Find sources', $research->id);
        $journals = $plans->addStep($by, $this->essay->id, 'Search the journals', $sources->id);
        $plans->addStep($by, $this->essay->id, 'Ask the librarian', $sources->id);
        $plans->addStep($by, $this->essay->id, 'Summarise', $research->id);

        $this->get($this->route('workspaces.assignments.sections.show', $research->id))->assertOk()->assertSee('Research')->assertSee('Cell essay')
            ->assertSee('Find sources')->assertSee('Search the journals')->assertSeeText('Tasks 2')->assertSeeText('Files 0')->assertSeeText('Notes 0');

        // One of two sub-tasks done: the task is half done, the section a quarter, the assignment 10 of its 40.
        $page = $this->page($research->id)->assertSee('0 of 2 tasks done')->assertSee('0 of 40% of the assignment earned');
        $page->call('setState', $journals->id, 'done')->assertSee('25%')->assertSee('10 of 40% of the assignment earned')->assertSee('0 of 2 tasks done');
        $this->assertSame(10, $plans->get($by, $this->essay->id)->progress()->percent);

        // A task is added to it, and a sub-task to a task.
        $page->call('addTask')->assertHasErrors(["stepText.{$research->id}"]);
        $page->set("stepText.{$research->id}", 'Write it up')->call('addTask')->assertHasNoErrors()->assertSee('Write it up')->assertSet('stepText', []);
        $page->set("stepText.{$sources->id}", 'Check the dates')->call('addStep', $sources->id)->assertSee('Check the dates');
        $this->assertCount(3, $plans->get($by, $this->essay->id)->steps($sources->id));
    }

    public function test_a_sections_files_folders_and_notes_are_kept_in_its_own_folder(): void
    {
        $plans = app(Plans::class);
        $by = $this->principal($this->ada);
        $research = $plans->addPart($by, $this->essay->id, 'Research', 40);

        $page = $this->page($research->id)->call('showTab', 'files')->assertSet('tab', 'files')->assertSee('No files yet')->assertSee('Upload files')->assertSee('New folder')
            ->assertDontSee('Its folder');

        // Its folder is made the first time something goes in: inside the assignment's folder.
        $page->call('addFolder')->assertHasErrors(['folderName']);
        $page->set('folderName', 'Sources')->call('addFolder')->assertHasNoErrors()->assertSee('Folder “Sources” added.')->assertSet('folderName', '')
            ->assertSee('Sources')->assertSee('Its folder')->assertDispatched('folder-added');
        $folder = app(Folders::class)->find($by, $plans->get($by, $this->essay->id)->item($research->id)->folderId);
        $this->assertSame(['Research', app(Activities::class)->find($by, $this->essay->id)->folderId], [$folder->name, $folder->parentId]);

        // Files go up into it (the uploader asks for the place), are listed, and go to the trash from their menu.
        $page->call('sectionFolder')->assertReturned(['folder', $folder->id]);
        $file = app(Files::class)->upload($by, 'folder', $folder->id, $this->temp($this->pdf()), 'Paper.pdf');
        $page->call('showTab', 'tasks')->call('uploadsFinished', 1, 0)->assertSet('tab', 'files')->assertSee('1 file added.')->assertSee('Paper.pdf')
            ->assertSee(route('files.content', [$file->id, 'download' => 1]));
        $page->call('trashFile', $file->id)->assertSee('Moved to the trash.')->assertDontSee('Paper.pdf');

        // A note is written in it.
        $page->call('showTab', 'notes')->assertSee('No notes yet')
            ->call('writeNote')->assertRedirect(route('workspaces.notes.create', [$this->databases->id, 'in' => "folder:{$folder->id}"]));
        $note = app(Notes::class)->create($by, 'folder', $folder->id, 'Reading list');
        $page->call('$refresh')->assertSee('Reading list')->call('trashNote', $note->id)->assertDontSee('Reading list');

        $page->call('showTab', 'nothing')->assertSet('tab', 'tasks');
    }

    public function test_deleting_a_section_goes_back_to_the_assignment_and_keeps_its_folder(): void
    {
        $plans = app(Plans::class);
        $by = $this->principal($this->ada);
        $research = $plans->addPart($by, $this->essay->id, 'Research');
        $folder = $plans->folder($by, $research->id);
        app(Notes::class)->create($by, 'folder', $folder->id, 'Reading list');

        // With no tasks, the section itself is ticked on its page.
        $this->page($research->id)->assertSee('Mark done')->call('setState', $research->id, 'done')->assertSee('100%')->assertDontSee('Mark done');

        $this->page($research->id)->call('deleteSection')->assertRedirect($this->route('workspaces.assignments.show'));
        $this->assertSame('The section “Research” is deleted. Its folder “Research” stays in the assignment\'s folder, with what is in it.', session('workspace-notice'));
        $this->assertSame([], $plans->get($by, $this->essay->id)->parts());
        $this->assertSame('Research', app(Folders::class)->find($by, $folder->id)->name);
        $this->get($this->route('workspaces.assignments.sections.show', $research->id))->assertNotFound();
    }

    public function test_a_section_page_is_only_for_a_section_of_that_assignment_and_its_ids_are_locked(): void
    {
        $plans = app(Plans::class);
        $by = $this->principal($this->ada);
        $research = $plans->addPart($by, $this->essay->id, 'Research');
        $step = $plans->addStep($by, $this->essay->id, 'Draft');
        $other = app(Activities::class)->create($by, $this->databases->id, ['title' => 'Lab report']);
        $elsewhere = $plans->addPart($by, $other->id, 'Method');

        $this->get($this->route('workspaces.assignments.sections.show', $step->id))->assertNotFound();
        $this->get($this->route('workspaces.assignments.sections.show', $elsewhere->id))->assertNotFound();
        $this->get($this->route('workspaces.assignments.sections.edit', $elsewhere->id))->assertNotFound();

        // Someone else sees none of it.
        $this->actingAs($this->student());
        $this->get($this->route('workspaces.assignments.sections.show', $research->id))->assertNotFound();
        $this->get($this->route('workspaces.assignments.sections.create'))->assertNotFound();

        $this->actingAs($this->ada);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $this->page($research->id)->set('sectionId', $elsewhere->id);
    }
}
