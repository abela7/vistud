<?php

namespace Tests\Feature\Web;

use App\Livewire\Study\PinnedNotes;
use App\Livewire\Workspaces\Contents;
use App\Livewire\Workspaces\NoteActions;
use App\Models\User;
use App\Study\FilePreviews;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\NoteDetails;
use App\Study\Notes;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The note page, notes in Modules, and Notes & files with its trash (docs/specs/workspaces.md step 3). */
class NotesScreenTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

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
            ->assertSeeInOrder(['Where this note is', 'Modules', 'Cells', 'Labs'])
            ->assertSee(route('workspaces.folders.show', [$this->biology->id, $labs->id]), false)
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

    public function test_a_note_is_pinned_from_its_page_and_from_the_list(): void
    {
        $by = $this->principal($this->ada);
        $notes = app(Notes::class);
        $mitosis = $notes->create($by, 'workspace', $this->biology->id, 'Mitosis');
        $meiosis = $notes->create($by, 'workspace', $this->biology->id, 'Meiosis');

        $this->actions($mitosis)
            ->assertSee('Pin')->assertDontSee('Unpin')
            ->call('togglePin')->assertSee('Pinned. Its button is now in the corner of every page.')->assertSee('Unpin')
            ->assertDispatched('pins-changed')
            ->call('togglePin')->assertSee('Unpinned.')->assertSee('title="Pin: keep a button', false);
        $this->assertSame([], $notes->pinned($by));

        $this->contents('notes')
            ->assertSee('Pin to the corner')->assertDontSee('Unpin')
            ->call('pinNote', $meiosis->id)->assertSee('“Meiosis” is pinned.')->assertDispatched('pins-changed')
            ->assertSee('Unpin')
            ->call('unpinNote', $meiosis->id)->assertSee('“Meiosis” is unpinned.');
        $this->assertSame([], $notes->pinned($by));

        // Pinned notes are as many as a student can keep; the next says so and changes nothing.
        $others = [];
        for ($n = 1; $n <= Notes::MAX_PINNED; $n++) {
            $others[] = $notes->create($by, 'workspace', $this->biology->id, "Extra {$n}");
            $notes->pin($by, $others[$n - 1]->id);
        }
        $this->contents('notes')->call('pinNote', $meiosis->id)->assertSee('You can pin 8 notes. Unpin one to pin another.');
        $this->actions($mitosis)->call('togglePin')->assertSee('You can pin 8 notes. Unpin one to pin another.')
            ->assertSee('data-toast-tone="info"', false);
        $this->assertCount(Notes::MAX_PINNED, $notes->pinned($by));
    }

    public function test_the_pinned_notes_are_a_button_on_every_student_page_but_their_own(): void
    {
        $by = $this->principal($this->ada);
        $notes = app(Notes::class);
        $mitosis = $notes->create($by, 'workspace', $this->biology->id, 'Mitosis');
        $meiosis = $notes->create($by, 'workspace', $this->biology->id, 'Meiosis');
        $overview = route('workspaces.show', [$this->biology->id, 'notes']);

        // Nothing pinned: nothing in the corner.
        $this->actingAs($this->ada)->get($overview)->assertOk()->assertDontSee('data-pin-dock', false);

        // One pin is a button that opens its note in a window of its own.
        $notes->pin($by, $mitosis->id);
        $window = route('workspaces.notes.show', [$this->biology->id, $mitosis->id, 'window' => 1]);
        $this->actingAs($this->ada)->get($overview)->assertOk()
            ->assertSee('data-pin-dock', false)
            ->assertSee('href="'.$window.'" target="vistud-note-'.$mitosis->id.'" data-note-window', false)
            ->assertSeeInOrder(['Pinned note: ', 'Mitosis']);
        $this->actingAs($this->ada)->get(route('home'))->assertOk()->assertSee('data-pin-dock', false);
        // On its own page it's already open.
        $this->actingAs($this->ada)->get(route('workspaces.notes.show', [$this->biology->id, $mitosis->id]))->assertOk()->assertDontSee('data-pin-dock', false);
        // In a window of its own there's no corner at all.
        $this->actingAs($this->ada)->get($window)->assertOk()->assertDontSee('data-pin-dock', false);

        // Several are one button and a list, each with its own unpin.
        $notes->pin($by, $meiosis->id);
        $this->actingAs($this->ada)->get($overview)->assertOk()
            ->assertSeeInOrder(['id="pin-menu"', 'Mitosis', 'Biology', 'Unpin Mitosis', 'Meiosis', 'Biology', 'Unpin Meiosis'])
            ->assertSee('aria-controls="pin-menu"', false);
        $this->actingAs($this->ada)->get(route('workspaces.notes.show', [$this->biology->id, $mitosis->id]))->assertOk()
            ->assertSee('Pinned note: ', false)->assertSee('Meiosis')->assertDontSee('id="pin-menu"', false);

        // Unpinning from the list is the component's own, and it doesn't reach another student's pins.
        $this->livewireSession();
        Livewire::test(PinnedNotes::class)
            ->assertSee('Pinned notes')
            ->call('unpin', $mitosis->id)->assertSee('“Mitosis” is unpinned.')->assertDispatched('pins-changed');
        $this->assertSame([$meiosis->id], array_map(fn ($n) => $n->id, $notes->pinned($by)));
        $bob = $this->student();
        $bobsNote = $notes->create($this->principal($bob), 'workspace', app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private'])->id, 'Secret');
        $notes->pin($this->principal($bob), $bobsNote->id);
        Livewire::test(PinnedNotes::class)->call('unpin', $bobsNote->id);
        $this->assertCount(1, $notes->pinned($this->principal($bob)));
        $this->actingAs($this->ada)->get($overview)->assertDontSee('Secret');
    }

    public function test_notes_are_created_and_listed_in_modules_and_in_notes_and_files(): void
    {
        $cells = app(Modules::class)->create($this->principal($this->ada), $this->biology->id, ['title' => 'Cells']);

        // New note opens an empty editor: nothing is made until something is written.
        $this->contents('modules')->call('newNote', 'module', $cells->id)->assertRedirect(route('workspaces.notes.create', [$this->biology->id, 'in' => "module:{$cells->id}"]));
        $this->contents('notes')->call('newNote', 'workspace', $this->biology->id)->assertRedirect(route('workspaces.notes.create', $this->biology->id));
        $this->assertSame([], $this->notes());
        $this->actingAs($this->ada)->get(route('workspaces.notes.create', [$this->biology->id, 'in' => "module:{$cells->id}"]))
            ->assertOk()->assertSee('<title>New note · Biology', false)->assertSeeInOrder(['Modules', 'Cells'])
            ->assertSee('data-create-url="'.route('api.v1.notes.store').'"', false)->assertSee('data-place-type="module"', false)
            ->assertSee('Not saved yet')->assertDontSee('data-save-url', false);
        $this->actingAs($this->ada)->get(route('workspaces.notes.create', [$this->biology->id, 'in' => 'module:nothing-here']))->assertNotFound();

        // Its first words make it.
        $by = $this->principal($this->ada);
        app(Notes::class)->createWritten($by, 'module', $cells->id, ['create_id' => 'first-words-1', 'title' => '', 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Cells divide.']]]]]]);
        app(Notes::class)->createWritten($by, 'workspace', $this->biology->id, ['create_id' => 'first-words-2', 'title' => '', 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Plan.']]]]]]);
        [$inModule, $topLevel] = $this->notes();
        $this->assertSame([$cells->id, null], [$inModule->moduleId, $topLevel->moduleId]);

        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->biology->id, 'modules']))
            ->assertSeeInOrder(['Cells', '1 note']);
        $this->actingAs($this->ada)->get(route('workspaces.modules.show', [$this->biology->id, $cells->id, 'tab' => 'notes']))
            ->assertOk()->assertSee('<title>Cells · Biology', false)
            ->assertSeeInOrder(['Modules', 'Cells', 'Study this', 'New note', 'Untitled note', 'Note · ']);
        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->biology->id, 'notes']))
            ->assertOk()->assertSee('<title>Notes &amp; files · Biology', false)
            ->assertSeeInOrder(['New', 'Untitled note', 'Trash (0)']);
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

    public function test_a_note_opens_in_a_window_of_its_own_with_only_the_note(): void
    {
        $by = $this->principal($this->ada);
        $cells = app(Modules::class)->create($by, $this->biology->id, ['title' => 'Cells']);
        $labs = app(Folders::class)->create($by, 'module', $cells->id, 'Labs');
        $note = app(Notes::class)->create($by, 'folder', $labs->id, 'Mitosis');

        // Its page offers a window of its own; the list's ⋯ menu too, one window per note.
        $this->actingAs($this->ada)->get(route('workspaces.notes.show', [$this->biology->id, $note->id]))
            ->assertOk()->assertSee('data-note-pop-out', false)->assertSee('New window')->assertSee('app-topbar', false)->assertDontSee('data-window', false);
        $this->actingAs($this->ada)->get(route('workspaces.folders.show', [$this->biology->id, $labs->id]))
            ->assertOk()->assertSee('Open in a new window')
            ->assertSee('href="'.route('workspaces.notes.show', [$this->biology->id, $note->id, 'window' => 1]).'"', false)
            ->assertSee('target="vistud-note-'.$note->id.'" data-note-window', false);

        // The window: the note and where it is, as words; no top bar, sidebar, menu or Back.
        $this->actingAs($this->ada)->get(route('workspaces.notes.show', [$this->biology->id, $note->id, 'window' => 1]))
            ->assertOk()
            ->assertSee('<title>Mitosis · Biology · ViStud</title>', false)
            ->assertSee('data-window', false)
            ->assertSee('data-note-full', false)
            ->assertSeeInOrder(['Where this note is', 'Biology', 'Cells', 'Labs', 'Open in ViStud'])
            ->assertSee('data-save-url="'.route('api.v1.notes.update', $note->id).'"', false)
            ->assertDontSee('app-topbar', false)->assertDontSee('data-back', false)->assertDontSee('data-note-pop-out', false)
            ->assertDontSee('Actions for Mitosis')->assertDontSee('wire:', false)
            ->assertDontSee(route('workspaces.folders.show', [$this->biology->id, $labs->id]), false);

        // A new note beside the study material: made in its place by its first words, like any other.
        $this->actingAs($this->ada)->get(route('workspaces.notes.create', [$this->biology->id, 'in' => "folder:{$labs->id}", 'window' => 1]))
            ->assertOk()->assertSee('data-window', false)
            ->assertSee('data-create-url="'.route('api.v1.notes.store').'"', false)->assertSee('data-place-id="'.$labs->id.'"', false);

        // In the trash it opens on its page, where it can be restored; another student's is missing.
        app(Notes::class)->trash($by, $note->id);
        $this->actingAs($this->ada)->get(route('workspaces.notes.show', [$this->biology->id, $note->id, 'window' => 1]))
            ->assertRedirect(route('workspaces.notes.show', [$this->biology->id, $note->id]));
        $bob = $this->student();
        $this->actingAs($bob)->get(route('workspaces.notes.show', [$this->biology->id, $note->id, 'window' => 1]))->assertNotFound();
    }

    public function test_a_note_downloads_as_markdown_and_as_plain_text(): void
    {
        $by = $this->principal($this->ada);
        $notes = app(Notes::class);
        $note = $notes->create($by, 'workspace', $this->biology->id, 'Mitosis: a summary / notes');
        $notes->save($by, $note->id, ['base_version' => 1, 'save_id' => 'export-save-1', 'client_id' => 'export-tab-1', 'title' => 'Mitosis: a summary / notes', 'doc' => ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Phases']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Energy '], ['type' => 'text', 'text' => 'matters', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => ': '], ['type' => 'inlineMath', 'attrs' => ['latex' => 'E = mc^2']], ['type' => 'text', 'text' => '.']]],
            ['type' => 'taskList', 'content' => [['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Prophase']]]]]]],
            ['type' => 'callout', 'attrs' => ['tone' => 'theorem'], 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Cells divide.']]]]],
            ['type' => 'blockMath', 'attrs' => ['latex' => '\frac{n(n+1)}{2}']],
        ]]]);

        // Markdown: the title as the heading, then the note as any Markdown reader shows it. Named after the title.
        $markdown = $this->actingAs($this->ada)->get(route('workspaces.notes.export', [$this->biology->id, $note->id, 'md']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="Mitosis a summary notes.md"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $markdown->headers->get('Cache-Control'));
        $this->assertSame(
            "# Mitosis: a summary / notes\n\n## Phases\n\nEnergy **matters**: \$E = mc^2\$.\n\n- [x] Prophase\n\n> [!THEOREM]\n> Cells divide.\n\n\$\$\n\\frac{n(n+1)}{2}\n\$\$\n",
            $markdown->getContent(),
        );

        // Plain text: the same words for Notepad, without markers.
        $this->actingAs($this->ada)->get(route('workspaces.notes.export', [$this->biology->id, $note->id, 'txt']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="Mitosis a summary notes.txt"')
            ->assertContent("Mitosis: a summary / notes\n\nPhases\n\nEnergy matters: E = mc^2.\n\n- [x] Prophase\n\nTheorem:\nCells divide.\n\n\\frac{n(n+1)}{2}\n");

        // A title with letters outside ASCII is kept in the file name, with an ASCII one for older browsers; no title is "Untitled note".
        $accents = $notes->create($by, 'workspace', $this->biology->id, 'Résumé — cellules');
        $disposition = (string) $this->actingAs($this->ada)->get(route('workspaces.notes.export', [$this->biology->id, $accents->id, 'md']))->assertOk()->headers->get('Content-Disposition');
        $this->assertStringContainsString('filename="Resume', $disposition);
        $this->assertStringContainsString("filename*=utf-8''R%C3%A9sum%C3%A9%20", $disposition);
        $untitled = $notes->create($by, 'workspace', $this->biology->id);
        $this->actingAs($this->ada)->get(route('workspaces.notes.export', [$this->biology->id, $untitled->id, 'txt']))
            ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="Untitled note.txt"')->assertContent("Untitled note\n");

        // Only the owner's note, in that workspace, in one of its formats.
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);
        $theirNote = $notes->create($this->principal($bob), 'workspace', $theirs->id, 'Secret');
        $maths = app(Workspaces::class)->create($by, ['name' => 'Mathematics']);
        $this->actingAs($this->ada)->get(route('workspaces.notes.export', [$theirs->id, $theirNote->id, 'md']))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.notes.export', [$this->biology->id, $theirNote->id, 'md']))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.notes.export', [$maths->id, $note->id, 'md']))->assertNotFound();
        $this->actingAs($this->ada)->get("/workspaces/{$this->biology->id}/notes/{$note->id}/export/html")->assertNotFound();
        $this->actingAs($bob)->get(route('workspaces.notes.export', [$this->biology->id, $note->id, 'txt']))->assertNotFound();
    }

    public function test_without_libreoffice_a_note_is_shared_as_markdown_or_text_only(): void
    {
        config(['vistud.files.office' => 'none']);
        $by = $this->principal($this->ada);
        $note = app(Notes::class)->create($by, 'workspace', $this->biology->id, 'Kernel notes');

        $page = $this->actingAs($this->ada)->get(route('workspaces.notes.show', [$this->biology->id, $note->id]))->assertOk();
        $page->assertSee('data-share-format="md"', false)->assertSee('data-share-format="txt"', false)->assertDontSee('data-share-format="pdf"', false);
        $this->actingAs($this->ada)->get(route('workspaces.notes.export', [$this->biology->id, $note->id, 'pdf']))
            ->assertStatus(422)->assertSee('PDF and Word copies need LibreOffice on this computer.');
    }

    public function test_with_libreoffice_a_note_is_shared_as_pdf_and_word_with_its_pictures(): void
    {
        if (FilePreviews::converter() === null) {
            $this->markTestSkipped('LibreOffice is not on this computer.');
        }
        Storage::fake('local');
        $by = $this->principal($this->ada);
        $notes = app(Notes::class);
        $note = $notes->create($by, 'workspace', $this->biology->id, 'Kernel notes');
        $picture = $this->actingAs($this->ada)->post(route('api.v1.notes.images.store'), ['image' => UploadedFile::fake()->createWithContent('cell.png', $this->image())], ['Accept' => 'application/json'])->assertCreated()->json('url');
        $notes->save($by, $note->id, ['base_version' => 1, 'save_id' => 'share-save-1', 'client_id' => 'share-tab-1', 'title' => 'Kernel notes', 'doc' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'System calls cross into the kernel.']]],
            ['type' => 'image', 'attrs' => ['src' => parse_url($picture, PHP_URL_PATH), 'alt' => 'A cell']],
        ]]]);

        $pdf = $this->actingAs($this->ada)->get(route('workspaces.notes.export', [$this->biology->id, $note->id, 'pdf']))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Disposition', 'attachment; filename="Kernel notes.pdf"');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertMatchesRegularExpression('~/Subtype\s*/Image~', $pdf->getContent());

        $word = $this->actingAs($this->ada)->get(route('workspaces.notes.export', [$this->biology->id, $note->id, 'docx']))
            ->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="Kernel notes.docx"');
        $zip = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($zip, $word->getContent());
        $archive = new \ZipArchive;
        $archive->open($zip);
        $this->assertStringContainsString('System calls cross into the kernel.', (string) $archive->getFromName('word/document.xml'));
        $this->assertNotFalse($archive->locateName('word/media/image1.png'));
        $archive->close();
        unlink($zip);
    }

    public function test_a_new_note_can_start_from_a_markdown_or_text_file_in_the_workspace(): void
    {
        Storage::fake('local');
        $by = $this->principal($this->ada);
        $cells = app(Modules::class)->create($by, $this->biology->id, ['title' => 'Cells']);
        $files = app(Files::class);
        $reading = $files->upload($by, 'module', $cells->id, $this->temp("# Reading\n\nCells divide.\n"), 'Reading.md');
        $plan = $files->upload($by, 'module', $cells->id, $this->temp("Plan\n"), 'Plan.txt');
        $slides = $files->upload($by, 'module', $cells->id, $this->temp($this->pdf()), 'Slides.pdf');

        // The editor is told where the file's words are and what they are; the browser brings them in.
        $this->actingAs($this->ada)->get(route('workspaces.notes.create', [$this->biology->id, 'from' => "file:{$reading->id}", 'in' => "module:{$cells->id}"]))
            ->assertOk()
            ->assertSee('data-import-url="'.route('files.content', $reading->id).'"', false)
            ->assertSee('data-import-name="Reading"', false)
            ->assertSee('data-import-kind="markdown"', false)
            ->assertSee('data-place-type="module"', false);
        $this->actingAs($this->ada)->get(route('workspaces.notes.create', [$this->biology->id, 'from' => "file:{$plan->id}"]))
            ->assertOk()->assertSee('data-import-kind="text"', false);
        $this->actingAs($this->ada)->get(route('workspaces.notes.create', $this->biology->id))->assertOk()->assertDontSee('data-import-url', false);

        // Only a Markdown or text file of the student's, in this workspace and not in the trash.
        $this->actingAs($this->ada)->get(route('workspaces.notes.create', [$this->biology->id, 'from' => "file:{$slides->id}"]))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.notes.create', [$this->biology->id, 'from' => 'file:nothing-here']))->assertNotFound();
        $maths = app(Workspaces::class)->create($by, ['name' => 'Mathematics']);
        $this->actingAs($this->ada)->get(route('workspaces.notes.create', [$maths->id, 'from' => "file:{$reading->id}"]))->assertNotFound();
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);
        $this->actingAs($bob)->get(route('workspaces.notes.create', [$theirs->id, 'from' => "file:{$reading->id}"]))->assertNotFound();
        $files->trash($by, $reading->id);
        $this->actingAs($this->ada)->get(route('workspaces.notes.create', [$this->biology->id, 'from' => "file:{$reading->id}"]))->assertNotFound();
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
