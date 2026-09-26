<?php

namespace Tests\Feature\Study;

use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\Gone;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\FileDetails;
use App\Study\Files;
use App\Study\Folders;
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Uploaded files: what is accepted, how it is kept, and the trash (docs/specs/workspaces.md step 4). */
class FilesTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private Files $files;

    private Principal $by;

    private WorkspaceDetails $biology;

    private ModuleDetails $cells;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->files = app(Files::class);
        $this->by = $this->principal($this->student());
        $this->biology = app(Workspaces::class)->create($this->by, ['name' => 'Biology']);
        $this->cells = app(Modules::class)->create($this->by, $this->biology->id, ['title' => 'Cells']);
    }

    public function test_real_files_of_each_kind_are_kept_privately_under_a_random_key(): void
    {
        $kept = [];
        foreach ([
            'Lecture 2.pdf' => $this->pdf(),
            'Essay.docx' => $this->ooxml('word/document.xml'),
            'Slides.pptx' => $this->ooxml('ppt/presentation.xml'),
            'Marks.xlsx' => $this->ooxml('xl/workbook.xml'),
            'Notes.odt' => $this->odf('application/vnd.oasis.opendocument.text'),
            'Old slides.ppt' => $this->ole(),
            'Microscope.JPEG' => $this->image('jpeg'),
            'Reading list.md' => "# Reading\n- Campbell, chapter 12 – café\n",
        ] as $name => $bytes) {
            $kept[] = $this->upload($name, $bytes);
        }

        $this->assertSame(
            [['Lecture 2.pdf', 'pdf', 'application/pdf'], ['Essay.docx', 'document', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], ['Slides.pptx', 'slides', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
                ['Marks.xlsx', 'spreadsheet', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], ['Notes.odt', 'document', 'application/vnd.oasis.opendocument.text'], ['Old slides.ppt', 'slides', 'application/vnd.ms-powerpoint'],
                ['Microscope.jpg', 'image', 'image/jpeg'], ['Reading list.md', 'text', 'text/markdown']],
            array_map(fn (FileDetails $f) => [$f->fileName(), $f->kind, $f->mime], $kept),
        );

        // Stored under learners/{learner}/files/{id}: never the uploaded name, never public.
        [$file, $key] = $this->files->content($this->by, $kept[0]->id);
        $this->assertSame("learners/{$this->by->learnerId}/files/{$file->id}", $key);
        $this->assertSame($this->pdf(), Storage::disk('local')->get($key));
        $this->assertSame(['PDF', true], [$file->typeLabel(), $file->previewable()]);
        $this->assertFalse($kept[1]->previewable());
    }

    public function test_a_file_must_really_be_what_its_name_says(): void
    {
        foreach ([
            'setup.exe' => "MZ\x90\x00program",
            'drawing.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'page.html' => '<script>alert(1)</script>',
            'Lecture.pdf' => "MZ\x90\x00a program renamed",
            'Essay.docx' => $this->pdf(),
            'Essay 2.docx' => $this->ooxml('ppt/presentation.xml'),
            'Photo.png' => $this->image('jpeg'),
            'Notes.txt' => "binary\0data",
            'Latin1.txt' => "caf\xE9",
            'Empty.pdf' => '',
            'Old.doc' => 'not a compound file',
        ] as $name => $bytes) {
            $this->assertThrows(fn () => $this->upload($name, $bytes), Unprocessable::class);
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_macros_and_scripts_are_refused(): void
    {
        foreach ([
            'Essay.docx' => $this->ooxml('word/document.xml', ['word/vbaProject.bin' => 'macros']),
            'Slides.pptx' => $this->ooxml('ppt/presentation.xml', ['ppt/activeX/activeX1.xml' => '<x/>']),
            'Old.doc' => $this->ole(macros: true),
            'Notes.odt' => $this->odf('application/vnd.oasis.opendocument.text', ['Basic/Standard/Module1.xml' => '<x/>']),
            'Form.pdf' => $this->pdf('/OpenAction << /S /JavaScript /JS (app.alert(1)) >>'),
            'Run.pdf' => $this->pdf('/AA << /O << /S /Launch /F (cmd.exe) >> >>'),
        ] as $name => $bytes) {
            try {
                $this->upload($name, $bytes);
                $this->fail("{$name} was accepted.");
            } catch (Unprocessable $e) {
                $this->assertMatchesRegularExpression('/macros|scripts/', $e->details['fields']['file'][0], $name);
            }
        }
    }

    public function test_names_are_cleaned_and_size_and_quota_are_limited(): void
    {
        $this->assertSame('passwd.txt', $this->upload('../../etc/passwd.txt', "root\n")->fileName());
        $this->assertSame('Lecture 2.pdf', $this->upload("C:\\Users\\ada\\Lecture\t2.PDF", $this->pdf())->fileName());

        config(['vistud.files.max_bytes' => 100]);
        $this->assertThrows(fn () => $this->upload('Big.txt', str_repeat('a', 101)), Unprocessable::class);

        config(['vistud.files.max_bytes' => 1000, 'vistud.files.quota_bytes' => strlen($this->pdf()) + 10]);
        $this->assertThrows(fn () => $this->upload('Third.pdf', $this->pdf()), Conflict::class);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_files_are_renamed_moved_trashed_restored_and_deleted_with_their_bytes(): void
    {
        $labs = app(Folders::class)->create($this->by, 'module', $this->cells->id, 'Labs');
        $file = $this->upload('lab1.pdf', $this->pdf(), 'folder', $labs->id);

        $this->files->rename($this->by, $file->id, '  Lab 1: microscopes.docx ');
        $this->assertSame('Lab 1: microscopes.docx.pdf', $this->files->find($this->by, $file->id)->fileName());

        $division = app(Modules::class)->create($this->by, $this->biology->id, ['title' => 'Cell division']);
        app(Folders::class)->move($this->by, $labs->id, 'module', $division->id);
        $this->assertSame($division->id, $this->files->find($this->by, $file->id)->moduleId);
        $this->assertThrows(fn () => app(Folders::class)->delete($this->by, $labs->id), Conflict::class);
        $this->assertThrows(fn () => app(Modules::class)->delete($this->by, $division->id), Conflict::class);

        $this->files->trash($this->by, $file->id);
        $this->assertThrows(fn () => $this->files->content($this->by, $file->id), Gone::class);
        app(Folders::class)->delete($this->by, $labs->id);
        $restored = $this->files->restore($this->by, $file->id);
        $this->assertSame([$division->id, null], [$restored->moduleId, $restored->folderId]);

        $this->assertThrows(fn () => $this->files->destroy($this->by, $file->id), Conflict::class);
        $this->files->trash($this->by, $file->id);
        $this->assertSame(0, $this->files->purgeTrash(LearnerScope::forJob($this->by->learnerId)));
        $this->travel(Files::TRASH_DAYS + 1)->days();
        $this->assertSame(1, $this->files->purgeTrash(LearnerScope::forJob($this->by->learnerId)));
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertThrows(fn () => $this->files->find($this->by, $file->id), NotFound::class);
    }

    public function test_another_students_file_is_missing(): void
    {
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private']);
        $path = $this->temp($this->pdf());
        $file = app(Files::class)->upload($bob, 'workspace', $theirs->id, $path, 'Secret.pdf');

        foreach ([
            fn () => $this->files->find($this->by, $file->id),
            fn () => $this->files->content($this->by, $file->id),
            fn () => $this->files->rename($this->by, $file->id, 'Mine'),
            fn () => $this->files->trash($this->by, $file->id),
            fn () => $this->files->move($this->by, $file->id, 'workspace', $this->biology->id),
            fn () => $this->files->upload($this->by, 'workspace', $theirs->id, $this->temp($this->pdf()), 'Mine.pdf'),
        ] as $attempt) {
            $this->assertThrows($attempt, NotFound::class);
        }
    }

    private function upload(string $name, string $bytes, string $placeType = 'module', ?string $placeId = null): FileDetails
    {
        return $this->files->upload($this->by, $placeType, $placeId ?? $this->cells->id, $this->temp($bytes), $name);
    }
}
