<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\Contents;
use App\Livewire\Workspaces\FileActions;
use App\Models\User;
use App\Study\FileDetails;
use App\Study\Files;
use App\Study\Folders;
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Uploading, the file page, and a file's bytes (docs/specs/workspaces.md step 4). */
class FilesScreenTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $biology;

    private ModuleDetails $cells;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->ada = $this->student();
        $this->biology = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Biology']);
        $this->cells = app(Modules::class)->create($this->principal($this->ada), $this->biology->id, ['title' => 'Cells']);
    }

    public function test_files_are_uploaded_one_at_a_time_and_the_refused_ones_say_why(): void
    {
        $this->contents('modules')
            ->call('uploadFiles', 'module', $this->cells->id)
            ->assertSee('Upload files to Cells')->assertSee('Choose a folder')
            ->assertSee('accept=".pdf,.docx', false)
            // The dialog tells the list it's done: with none refused it closes and says how many.
            ->call('uploadsFinished', 2, 0)->assertSee('2 files uploaded.')->assertSet('mode', null);

        $post = fn (UploadedFile $file, array $more = []) => $this->actingAs($this->ada)->post(route('api.v1.files.store'), ['file' => $file, 'place_type' => 'module', 'place_id' => $this->cells->id] + $more, ['Accept' => 'application/json']);
        $post(UploadedFile::fake()->createWithContent('Lecture 2.pdf', $this->pdf()))->assertCreated()->assertJsonPath('name', 'Lecture 2.pdf')->assertJsonPath('place.type', 'module');
        $post(UploadedFile::fake()->createWithContent('Essay.docm', 'macros'))->assertUnprocessable();
        $post(UploadedFile::fake()->createWithContent('Slides.pdf', "MZ\x90\x00program"))->assertUnprocessable()->assertJsonPath('error.details.fields.file.0', 'This file isn\'t really a PDF.');

        // A file from an uploaded folder goes into the folders it was in, made once and found after.
        $post(UploadedFile::fake()->createWithContent('Lecture 1.pdf', $this->pdf()), ['folder' => 'Week 1/Lectures'])->assertCreated()->assertJsonPath('place.type', 'folder');
        $post(UploadedFile::fake()->createWithContent('Lab 1.pdf', $this->pdf()), ['folder' => 'week 1\\Labs'])->assertCreated();
        $post(UploadedFile::fake()->createWithContent('Macros.docm', 'x'), ['folder' => 'Refused/Never made'])->assertUnprocessable();
        $folders = app(Folders::class)->tree($this->principal($this->ada), $this->biology->id);
        $this->assertEqualsCanonicalizing(['Week 1', 'Lectures', 'Labs'], array_map(fn ($f) => $f->name, $folders));
        $this->assertEqualsCanonicalizing(['Lecture 2.pdf', 'Lecture 1.pdf', 'Lab 1.pdf'], array_map(fn (FileDetails $f) => $f->fileName(), $this->files()));

        // Another student's place is missing.
        $theirs = app(Workspaces::class)->create($this->principal($this->student()), ['name' => 'Theirs']);
        $this->actingAs($this->ada)->post(route('api.v1.files.store'), ['file' => UploadedFile::fake()->createWithContent('x.pdf', $this->pdf()), 'place_type' => 'workspace', 'place_id' => $theirs->id], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_a_file_is_renamed_moved_trashed_restored_and_deleted_from_the_lists(): void
    {
        $file = $this->stored('Lecture 2.pdf', $this->pdf());

        $page = $this->contents('notes')
            ->call('renameFile', $file->id)->assertSet('name', 'Lecture 2')
            ->set('name', 'Lecture 2: cell division')->call('save')
            ->assertSee('“Lecture 2: cell division.pdf” is renamed.')
            ->call('moveFile', $file->id)->set('destination', "workspace:{$this->biology->id}")->call('save')
            ->assertSee('“Lecture 2: cell division.pdf” is moved.')
            ->call('trashFile', $file->id)->assertSee('is in the trash.')
            ->call('toggleTrash')->assertSeeInOrder(['Hide the trash', '(1)', 'Lecture 2: cell division.pdf', 'Restore'])
            ->call('restoreFile', $file->id)->assertSee('is restored.');
        $this->assertSame([null, null], [$this->files()[0]->moduleId, $this->files()[0]->folderId]);

        app(Files::class)->trash($this->principal($this->ada), $file->id);
        $page->call('confirmDelete', 'file', $file->id)->assertSee('for good?')->call('save')->assertSee('is deleted.');
        $this->assertSame([], Storage::disk('local')->allFiles('learners'));
    }

    public function test_the_file_page_previews_what_the_browser_can_show_and_offers_the_rest_as_a_download(): void
    {
        $pdf = $this->stored('Lecture 2.pdf', $this->pdf());
        $docx = $this->stored('Essay.docx', $this->ooxml('word/document.xml'));
        $text = $this->stored('Reading.txt', "<script>alert(1)</script>\n");

        $this->page($pdf)->assertOk()
            ->assertSee('<title>Lecture 2.pdf · Biology', false)
            ->assertSeeInOrder(['Where this file is', 'Modules', 'Cells'])
            ->assertSee(route('workspaces.modules.show', [$this->biology->id, $this->cells->id]), false)
            ->assertSee('<iframe class="file-preview" data-pdf-src="'.route('files.content', $pdf->id).'"', false)
            ->assertSee(route('files.content', [$pdf->id, 'download' => 1]), false);
        // Word, PowerPoint and Excel show as a PDF made by LibreOffice; without it, the page says how to get it.
        config(['vistud.files.office' => 'none']);
        $this->page($docx)->assertSee('Word document files show here once LibreOffice is on this computer')->assertDontSee('<iframe', false);
        config(['vistud.files.office' => PHP_BINARY]);
        $this->page($docx)->assertSee('Preparing the preview')->assertSee('src="'.route('files.preview', $docx->id).'"', false);
        // Text is shown on the page, escaped.
        $this->page($text)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)', false);

        $this->actions($pdf)->call('trash')->assertRedirect(route('workspaces.show', [$this->biology->id, 'notes']));
        $this->page($pdf)->assertOk()->assertSee('This file is in the trash')->assertDontSee('<iframe', false);
    }

    public function test_the_bytes_are_sent_with_the_checked_type_and_nothing_that_could_run(): void
    {
        $pdf = $this->stored('Lecture 2.pdf', $this->pdf());
        $docx = $this->stored('Essay.docx', $this->ooxml('word/document.xml'));
        $text = $this->stored('Reading.md', "# Reading\n");

        $shown = $this->actingAs($this->ada)->get(route('files.content', $pdf->id))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringStartsWith('inline;', $shown->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $shown->headers->get('Cache-Control'));
        $this->assertSame($this->pdf(), $shown->streamedContent());

        $this->assertStringStartsWith('attachment;', $this->get(route('files.content', $docx->id))->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('attachment;', $this->get(route('files.content', [$pdf->id, 'download' => 1]))->headers->get('Content-Disposition'));
        $this->get(route('files.content', $text->id))
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox");
    }

    public function test_another_students_file_is_missing_and_a_trashed_one_is_gone(): void
    {
        $file = $this->stored('Lecture 2.pdf', $this->pdf());
        $bob = $this->student();

        $this->actingAs($bob)->get(route('files.content', $file->id))->assertNotFound();
        $this->actingAs($bob)->get(route('workspaces.files.show', [$this->biology->id, $file->id]))->assertNotFound();
        $maths = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Mathematics']);
        $this->actingAs($this->ada)->get(route('workspaces.files.show', [$maths->id, $file->id]))->assertNotFound();

        app(Files::class)->trash($this->principal($this->ada), $file->id);
        $this->actingAs($this->ada)->get(route('files.content', $file->id))->assertStatus(410);
    }

    public function test_a_markdown_file_is_shown_as_it_was_meant_to_look_and_opens_as_a_note(): void
    {
        $file = $this->stored('Cells.md', implode("\n", [
            '# Cells', '',
            'A **bold** word, a [link](javascript:alert(1)) and $E = mc^2$.', '',
            '| Phase | Order |', '|---|---|', '| Prophase | 1 |', '',
            '- [x] Read chapter 3', '',
            '<img src=x onerror=alert(1)>', '<script>alert(2)</script>', '',
        ]));

        $page = $this->page($file)->assertOk()
            ->assertSee('<h1>Cells</h1>', false)
            ->assertSee('<strong>bold</strong>', false)
            ->assertSee('<table>', false)
            ->assertSee('type="checkbox"', false)
            ->assertSee('$E = mc^2$')
            ->assertSee('data-formulas', false)
            ->assertDontSee('onerror', false)
            ->assertDontSee('alert(', false)
            ->assertDontSee('javascript:', false)
            ->assertDontSee('<pre class="file-preview file-text"', false);
        // Open as a note: a new note in the same place, made from the file (App\Http\Controllers\NotePageController).
        $page->assertSee(route('workspaces.notes.create', [$this->biology->id, 'from' => "file:{$file->id}", 'in' => "module:{$this->cells->id}"]));
        // Take notes: the file's own note, "Cells (notes)", in the same place, beside the file; made as soon as it opens.
        $page->assertSee(route('workspaces.notes.create', [$this->biology->id, 'in' => "module:{$this->cells->id}", 'window' => 1, 'title' => 'Cells (notes)']))
            ->assertSee('target="_blank" data-note-window', false);
        $this->get(route('workspaces.notes.create', [$this->biology->id, 'in' => "module:{$this->cells->id}", 'window' => 1, 'title' => 'Cells (notes)']))
            ->assertOk()->assertSee('data-note-title>Cells (notes)</textarea>', false);
        // Once it is there, Take notes opens that same note again, never a second one.
        $note = app(Notes::class)->create($this->principal($this->ada), 'module', $this->cells->id, 'Cells (notes)');
        $this->page($file)->assertSee(route('workspaces.notes.show', [$this->biology->id, $note->id, 'window' => 1]))
            ->assertDontSee('title=Cells');

        $this->actions($file)->call('trash');
        $this->page($file)->assertOk()->assertDontSee('Open as a note')->assertDontSee('Take notes')->assertDontSee('<h1>Cells</h1>', false);
    }

    private function stored(string $name, string $bytes): FileDetails
    {
        return app(Files::class)->upload($this->principal($this->ada), 'module', $this->cells->id, $this->temp($bytes), $name);
    }

    /** @return list<FileDetails> */
    private function files(): array
    {
        return app(Files::class)->list($this->principal($this->ada), $this->biology->id);
    }

    private function page(FileDetails $file)
    {
        return $this->actingAs($this->ada)->get(route('workspaces.files.show', [$this->biology->id, $file->id]));
    }

    private function contents(string $view)
    {
        $this->livewireSession();

        return Livewire::test(Contents::class, ['workspaceId' => $this->biology->id, 'view' => $view]);
    }

    private function actions(FileDetails $file)
    {
        $this->livewireSession();

        return Livewire::test(FileActions::class, ['fileId' => $file->id]);
    }

    private function livewireSession(): void
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
    }
}
