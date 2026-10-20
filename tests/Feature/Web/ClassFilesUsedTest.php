<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Request;
use App\Engine\Settings;
use App\Livewire\Workspaces\ClassRecorder;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\AudioPieces;
use App\Study\ClassHeader;
use App\Study\ClassRecordings;
use App\Study\ClassSessions;
use App\Study\Files;
use App\Study\Modules;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * The files the teacher taught from: ticked once on the class page, kept with the class, told to the AI that writes the class
 * down, and written at the top of the transcript and the lecture notes, so whoever reads them (the AI tutor in a study session
 * or an assignment, or a person sent the note) knows what the teacher was teaching from.
 */
class ClassFilesUsedTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $course;

    private string $module;

    private string $class;

    private string $slides;

    private string $handout;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
        $this->course = app(Workspaces::class)->create($this->by, ['name' => 'Biology'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->course, ['title' => 'Cells'])->id;
        $this->class = app(ClassSessions::class)->create($this->by, $this->course, $this->module, ['title' => 'Lecture · 20 Oct 2026', 'held_on' => '2026-10-20', 'teacher' => 'Dr Smith'])->id;
        $this->slides = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp('Mitosis has four phases.'), 'Week 3 slides.txt')->id;
        $this->handout = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp('Meiosis handout.'), 'Handout.txt')->id;
        $recordings = app(ClassRecordings::class);
        $recordings->allow($this->by, $this->class);
        $part = $recordings->start($this->by, $this->class);
        $recordings->accept($this->by, $this->class, $part->id, 1, $this->temp('webm bytes'), 'audio/webm');
        $recordings->finish($this->by, $this->class, $part->id, 600);
        $this->app->instance(AudioPieces::class, new class extends AudioPieces
        {
            public function split(string $file, int $seconds, string $directory): array
            {
                File::ensureDirectoryExists($directory);
                File::put($directory.'/piece-000.mp3', 'first');

                return [$directory.'/piece-000.mp3'];
            }
        });
    }

    private function page()
    {
        return Livewire::actingAs($this->ada)->test(ClassRecorder::class, ['workspaceId' => $this->course, 'moduleId' => $this->module, 'classId' => $this->class]);
    }

    private function text(?string $noteId): string
    {
        return NoteDoc::text(app(Notes::class)->open($this->by, (string) $noteId)->doc ?? []);
    }

    public function test_ticking_a_file_keeps_it_with_the_class_and_unticking_removes_it(): void
    {
        $page = $this->page()->assertSee('Files the teacher used')->assertSee('Week 3 slides.txt')->assertSee('Handout.txt');

        $page->set('checkFiles', [$this->slides, $this->handout]);
        $this->assertEqualsCanonicalizing([$this->slides, $this->handout], app(ClassSessions::class)->find($this->by, $this->class)->checkFiles);

        $page->set('checkFiles', [$this->slides]);
        $this->assertSame([$this->slides], app(ClassSessions::class)->find($this->by, $this->class)->checkFiles);
    }

    public function test_the_ai_is_told_the_files_and_both_notes_start_by_saying_what_the_teacher_taught_from(): void
    {
        $this->page()->set('checkFiles', [$this->slides]);
        $this->engine->will(
            function (Request $request) {
                $this->assertStringContainsString('The teacher taught from these files', $request->messages[0]['content'][0]['text']);
                $this->assertStringContainsString('Week 3 slides.txt', $request->messages[0]['content'][0]['text']);

                return Fake::says('Mitosis has four phases.', 1000, 'google/gemini-2.5-flash');
            },
            Fake::says('## 00:00–10:00 Mitosis has four phases.', 1000),
        );

        $this->page()->call('transcribe');

        $class = app(ClassSessions::class)->find($this->by, $this->class);
        foreach ([$class->transcriptNoteId, $class->lectureNotesId] as $noteId) {
            $text = $this->text($noteId);
            $this->assertStringStartsWith('Class: Lecture · 20 Oct 2026 (Cells, Biology), teacher Dr Smith', $text);
            $this->assertStringContainsString('The teacher taught from: Week 3 slides.txt', $text);
        }
        $this->assertStringContainsString('Mitosis has four phases.', $this->text($class->transcriptNoteId));
        $this->assertStringContainsString('word for word', $this->text($class->transcriptNoteId));
        $this->assertStringContainsString("teacher's teaching", $this->text($class->lectureNotesId));
    }

    public function test_changing_the_files_later_rewrites_the_line_at_the_top_and_keeps_the_rest(): void
    {
        $this->page()->set('checkFiles', [$this->slides]);
        $this->engine->will(Fake::says('Mitosis has four phases.', 1000, 'google/gemini-2.5-flash'), Fake::says('## 00:00–10:00 Notes body', 1000));
        $this->page()->call('transcribe');

        $this->page()->set('checkFiles', [$this->slides, $this->handout]);

        $class = app(ClassSessions::class)->find($this->by, $this->class);
        $lecture = $this->text($class->lectureNotesId);
        $this->assertStringContainsString('Week 3 slides.txt; Handout.txt', $lecture);
        $this->assertSame(1, substr_count($lecture, 'The teacher taught from:'));
        $this->assertStringContainsString('Notes body', $lecture);
        $this->assertStringContainsString('Mitosis has four phases.', $this->text($class->transcriptNoteId));
    }

    public function test_a_class_with_no_files_ticked_says_so_and_a_note_the_student_rewrote_is_left_alone(): void
    {
        $this->engine->will(Fake::says('Words.', 1000, 'google/gemini-2.5-flash'), Fake::says('Notes body', 1000));
        $this->page()->call('transcribe');
        $class = app(ClassSessions::class)->find($this->by, $this->class);
        $this->assertStringContainsString('The teacher taught from: not said yet', $this->text($class->lectureNotesId));

        // The student rewrote the top of the note: no header to find, so nothing is changed.
        app(Notes::class)->replace($this->by, (string) $class->lectureNotesId, [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'My own version']]]]);
        $this->page()->set('checkFiles', [$this->slides]);

        $this->assertSame('My own version', $this->text($class->lectureNotesId));
    }

    public function test_another_students_file_cannot_be_ticked_and_the_header_never_names_it(): void
    {
        $grace = $this->principal($this->student());
        $theirCourse = app(Workspaces::class)->create($grace, ['name' => 'Chemistry'])->id;
        $theirModule = app(Modules::class)->create($grace, $theirCourse, ['title' => 'Acids'])->id;
        $theirs = app(Files::class)->upload($grace, 'module', $theirModule, $this->temp('Secret'), 'Secret slides.txt')->id;

        $this->page()->set('checkFiles', [$theirs, $this->slides]);

        $class = app(ClassSessions::class)->find($this->by, $this->class);
        $this->assertSame([$this->slides], $class->checkFiles);
        $this->assertSame(['Week 3 slides.txt'], app(ClassHeader::class)->fileNames($this->by, $class));
    }
}
