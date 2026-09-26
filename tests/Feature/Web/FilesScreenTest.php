<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\Contents;
use App\Livewire\Workspaces\FileActions;
use App\Models\User;
use App\Study\FileDetails;
use App\Study\Files;
use App\Study\ModuleDetails;
use App\Study\Modules;
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

    public function test_uploading_keeps_the_good_files_and_says_why_the_others_were_refused(): void
    {
        $this->contents('modules')
            ->call('uploadFiles', 'module', $this->cells->id)
            ->assertSee('Upload files to Cells')
            ->assertSee('accept=".pdf,.docx', false)
            ->set('uploads', [
                UploadedFile::fake()->createWithContent('Lecture 2.pdf', $this->pdf()),
                UploadedFile::fake()->createWithContent('Essay.docm', 'macros'),
                UploadedFile::fake()->createWithContent('Slides.pdf', "MZ\x90\x00program"),
            ])
            ->call('save')
            ->assertSee('1 file uploaded.')
            ->assertSet('mode', 'upload')
            ->assertSee('These files weren&#039;t uploaded', false)
            ->assertSee('Essay.docm')
            ->assertSee('This file isn&#039;t really a PDF.', false);

        $this->assertSame(['Lecture 2.pdf'], array_map(fn (FileDetails $f) => $f->fileName(), $this->files()));
        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->biology->id, 'modules']))
            ->assertSeeInOrder(['Cells', '1 file', 'Lecture 2.pdf', 'PDF · ', 'Upload files']);
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
        $text = $this->stored('Reading.md', "# Reading\n<script>alert(1)</script>\n");

        $this->page($pdf)->assertOk()
            ->assertSee('<title>Lecture 2.pdf · Biology', false)
            ->assertSeeInOrder(['Where this file is', 'Biology', 'Cells'])
            ->assertSee('<iframe class="file-preview" src="'.route('files.content', $pdf->id).'"', false)
            ->assertSee(route('files.content', [$pdf->id, 'download' => 1]), false);
        $this->page($docx)->assertSee('No preview for Word document files yet')->assertDontSee('<iframe', false);
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
