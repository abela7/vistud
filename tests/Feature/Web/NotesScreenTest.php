<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\Contents;
use App\Livewire\Workspaces\NoteActions;
use App\Models\User;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\NoteDetails;
use App\Study\Notes;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The note page, notes in Modules, and Notes & files with its trash (docs/specs/workspaces.md step 3). */
class NotesScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $biology;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ada = $this->student();
        $this->biology = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Biology']);
    }

    public function test_the_note_page_holds_the_editor_and_where_the_note_is(): void
    {
        $by = $this->principal($this->ada);
        $cells = app(Modules::class)->create($by, $this->biology->id, ['title' => 'Cells']);
        $labs = app(Folders::class)->create($by, 'module', $cells->id, 'Labs');
        $note = app(Notes::class)->create($by, 'folder', $labs->id, 'Lab 1 </script><b>');

        $this->actingAs($this->ada)->get(route('workspaces.notes.show', [$this->biology->id, $note->id]))
            ->assertOk()
            ->assertSee('<title>Lab 1 &lt;/script&gt;&lt;b&gt; · Biology', false)
            ->assertSeeInOrder(['Where this note is', 'Biology', 'Cells', 'Labs'])
            ->assertSee('data-account="'.$this->ada->id.'"', false)
            ->assertSee('data-save-url="'.route('api.v1.notes.update', $note->id).'"', false)
            ->assertSee('role="toolbar" aria-label="Formatting"', false)
            // The document is JSON in a script tag, with nothing that could close it early.
            ->assertDontSee('Lab 1 </script>', false);
    }

    public function test_another_students_note_or_one_from_another_workspace_is_missing(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);
        $theirNote = app(Notes::class)->create($this->principal($bob), 'workspace', $theirs->id, 'Secret');
        $maths = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Mathematics']);
        $mine = app(Notes::class)->create($this->principal($this->ada), 'workspace', $maths->id, 'Algebra');

        $this->actingAs($this->ada)->get(route('workspaces.notes.show', [$this->biology->id, $theirNote->id]))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.notes.show', [$theirs->id, $theirNote->id]))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.notes.show', [$this->biology->id, $mine->id]))->assertNotFound();
    }

    public function test_a_note_is_trashed_from_its_page_and_restored_from_it(): void
    {
        $note = app(Notes::class)->create($this->principal($this->ada), 'workspace', $this->biology->id, 'Mitosis');

        $this->actions($note)->call('trash')->assertRedirect(route('workspaces.show', [$this->biology->id, 'notes']));
        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->biology->id, 'notes']))
            ->assertSee('“Mitosis” is in the trash.');

        $this->actingAs($this->ada)->get(route('workspaces.notes.show', [$this->biology->id, $note->id]))
            ->assertOk()->assertSee('This note is in the trash')->assertDontSee('data-note-editor', false);
        $this->actions($note)->call('restore')->assertRedirect(route('workspaces.notes.show', [$this->biology->id, $note->id]));
        $this->assertNull(app(Notes::class)->find($this->principal($this->ada), $note->id)->trashedAt);
    }

    public function test_notes_are_created_and_listed_in_modules_and_in_notes_and_files(): void
    {
        $cells = app(Modules::class)->create($this->principal($this->ada), $this->biology->id, ['title' => 'Cells']);

        $this->contents('modules')->call('newNote', 'module', $cells->id)->assertRedirectContains("/workspaces/{$this->biology->id}/notes/");
        $this->contents('notes')->call('newNote', 'workspace', $this->biology->id);
        [$inModule, $topLevel] = $this->notes();
        $this->assertSame([$cells->id, null], [$inModule->moduleId, $topLevel->moduleId]);

        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->biology->id, 'modules']))
            ->assertSeeInOrder(['Cells', '1 note', 'Untitled note', 'New note', 'New folder']);
        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->biology->id, 'notes']))
            ->assertOk()->assertSee('<title>Notes &amp; files · Biology', false)
            ->assertSeeInOrder(['2 notes', 'Recently edited', 'Not in a module', 'Untitled note', 'Trash (0)']);
    }

    public function test_the_trash_restores_and_deletes_for_good(): void
    {
        $notes = app(Notes::class);
        $mitosis = $notes->create($this->principal($this->ada), 'workspace', $this->biology->id, 'Mitosis');

        $page = $this->contents('notes')
            ->call('trashNote', $mitosis->id)->assertSee('“Mitosis” is in the trash.')
            ->call('toggleTrash')->assertSeeInOrder(['Hide the trash', '(1)', 'Mitosis', 'Restore', 'Delete for good'])
            ->call('restoreNote', $mitosis->id)->assertSee('“Mitosis” is restored.');
        $this->assertCount(1, $this->notes());

        $notes->trash($this->principal($this->ada), $mitosis->id);
        $page->call('confirmDelete', 'note', $mitosis->id)
            ->assertSee('Delete “Mitosis” for good?')
            ->call('save')
            ->assertSee('“Mitosis” is deleted.');
        $this->assertSame([], $notes->trashed($this->principal($this->ada), $this->biology->id));
    }

    public function test_a_note_moves_between_places_through_the_dialog(): void
    {
        $by = $this->principal($this->ada);
        $cells = app(Modules::class)->create($by, $this->biology->id, ['title' => 'Cells']);
        $exams = app(Folders::class)->create($by, 'workspace', $this->biology->id, 'Exam prep');
        $note = app(Notes::class)->create($by, 'module', $cells->id, 'Mitosis');

        $page = $this->contents('modules')->call('moveNote', $note->id)
            ->assertSet('destination', "module:{$cells->id}")
            ->assertSee('Move “Mitosis”')
            ->assertSeeInOrder(['Biology (not in a module)', 'Exam prep', 'Cells']);
        $page->set('destination', "folder:{$exams->id}")->call('save')->assertSee('“Mitosis” is moved.');
        $this->assertSame([null, $exams->id], [$this->notes()[0]->moduleId, $this->notes()[0]->folderId]);
    }

    public function test_the_browser_cannot_change_the_section_or_the_note(): void
    {
        $note = app(Notes::class)->create($this->principal($this->ada), 'workspace', $this->biology->id, 'Mitosis');

        $this->assertThrows(fn () => $this->contents('modules')->set('view', 'notes'), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $this->actions($note)->set('noteId', 'someone-elses'), CannotUpdateLockedPropertyException::class);
    }

    /** @return list<NoteDetails> */
    private function notes(): array
    {
        return app(Notes::class)->list($this->principal($this->ada), $this->biology->id);
    }

    private function contents(string $view)
    {
        $this->livewireSession();

        return Livewire::test(Contents::class, ['workspaceId' => $this->biology->id, 'view' => $view]);
    }

    private function actions(NoteDetails $note)
    {
        $this->livewireSession();

        return Livewire::test(NoteActions::class, ['noteId' => $note->id]);
    }

    private function livewireSession(): void
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
    }
}
