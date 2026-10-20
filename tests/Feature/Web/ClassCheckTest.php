<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Request;
use App\Engine\Settings;
use App\Livewire\Workspaces\ClassRecorder;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Study\ClassNote;
use App\Study\ClassRecordings;
use App\Study\ClassSessions;
use App\Study\Files;
use App\Study\MarkdownDoc;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** "Check against your files": the lecture notes set beside the slides the student ticked, in one note of the module. */
class ClassCheckTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $course;

    private string $module;

    private string $class;

    private string $slides;

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
        $this->class = app(ClassSessions::class)->create($this->by, $this->course, $this->module, ['title' => 'Lecture · 20 Oct 2026', 'held_on' => '2026-10-20'])->id;
        $this->slides = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp("Mitosis has four phases.\nProphase, metaphase, anaphase, telophase.\n"), 'Week 3 slides.txt')->id;
    }

    private function lectureNotes(): void
    {
        $notes = app(Notes::class);
        $one = $notes->create($this->by, 'module', $this->module, 'Lecture notes · Lecture · 20 Oct 2026');
        $notes->append($this->by, $one->id, MarkdownDoc::blocks("## 00:00–10:00\n\nThe teacher said mitosis has five phases. [unclear]"));
        $two = $notes->create($this->by, 'module', $this->module, 'Transcript');
        app(ClassSessions::class)->keepTranscript($this->by, $this->class, $two->id, $one->id);
    }

    private function recorded(): void
    {
        $recordings = app(ClassRecordings::class);
        $recordings->allow($this->by, $this->class);
        $part = $recordings->start($this->by, $this->class);
        $recordings->accept($this->by, $this->class, $part->id, 1, $this->temp('webm bytes'), 'audio/webm');
        $recordings->finish($this->by, $this->class, $part->id, 600);
    }

    private function page()
    {
        return Livewire::actingAs($this->ada)->test(ClassRecorder::class, ['workspaceId' => $this->course, 'moduleId' => $this->module, 'classId' => $this->class]);
    }

    public function test_the_card_waits_for_the_lecture_notes(): void
    {
        $this->page()->assertDontSee('Check against your files');

        $this->lectureNotes();
        $this->recorded();
        $this->page()->assertSee('Check against your files')->assertSee('Week 3 slides.txt');
    }

    public function test_the_ticked_files_are_compared_with_the_lecture_notes_and_kept_as_one_note_in_the_module(): void
    {
        $this->lectureNotes();
        $this->recorded();
        app(ClassNote::class)->write($this->by, $this->class, [['from' => '2:00', 'until' => '2:30', 'about' => 'Phases?', 'body' => 'How many phases are there?']], live: true);
        $this->engine->will(function (Request $request) {
            $this->assertSame('fake/quick', $request->model);
            $this->assertStringContainsString('## Different from your files', $request->system);
            $text = (string) $request->messages[0]['content'];
            $this->assertStringContainsString('five phases', $text);
            $this->assertStringContainsString('### File: Week 3 slides.txt', $text);
            $this->assertStringContainsString('Prophase, metaphase', $text);
            $this->assertStringContainsString('2:00–2:30: How many phases are there?', $text);
            $this->assertStringContainsString('<material>', $text);

            return Fake::says("## Different from your files\n\n- At 00:00 the class said five phases; Week 3 slides, page 1 says four.", 3000);
        });

        $this->page()->set('checkFiles', [$this->slides])->call('check')->assertSee('Class check')->assertSee('Check again');

        $class = app(ClassSessions::class)->find($this->by, $this->class);
        $this->assertSame([$this->slides], $class->checkFiles);
        $this->assertSame('Class check · Lecture · 20 Oct 2026', LearnerTables::query(LearnerScope::of($this->by), 'notes')->where('id', $class->checkNoteId)->value('title'));
        $this->assertStringContainsString('says four', (string) LearnerTables::query(LearnerScope::of($this->by), 'note_versions')->where('note_id', $class->checkNoteId)->orderByDesc('version')->value('doc'));
        $this->assertSame(1, DB::table('engine_jobs')->where('kind', 'class_check')->where('status', 'done')->count());
    }

    public function test_checking_again_adds_a_version_to_the_same_note(): void
    {
        $this->lectureNotes();
        $this->recorded();
        $this->engine->will(Fake::says('## Different from your files\n\nFirst.', 1000), Fake::says('## Different from your files\n\nSecond.', 1000));

        $this->page()->set('checkFiles', [$this->slides])->call('check');
        $first = app(ClassSessions::class)->find($this->by, $this->class)->checkNoteId;
        $this->page()->call('check');
        $second = app(ClassSessions::class)->find($this->by, $this->class)->checkNoteId;

        $this->assertSame($first, $second);
        $this->assertCount(1, LearnerTables::query(LearnerScope::of($this->by), 'notes')->get(['title'])->filter(fn ($row) => str_starts_with($row->title, 'Class check')));
    }

    public function test_with_no_file_ticked_it_says_so_and_asks_nothing_of_the_ai(): void
    {
        $this->lectureNotes();
        $this->recorded();

        $this->page()->set('checkFiles', [])->call('check')->assertSee('Tick the files the teacher used');

        $this->assertSame(0, DB::table('engine_jobs')->where('kind', 'class_check')->count());
    }

    public function test_a_file_with_nothing_to_read_is_left_out_and_if_none_can_be_read_it_says_so(): void
    {
        $this->lectureNotes();
        $this->recorded();
        $empty = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp('   '), 'Blank.txt')->id;

        $this->page()->set('checkFiles', [$empty])->call('check')->assertSee('None of the ticked files has text to compare');

        $this->assertNull(app(ClassSessions::class)->find($this->by, $this->class)->checkNoteId);
    }

    public function test_another_students_file_cannot_be_ticked(): void
    {
        $this->lectureNotes();
        $this->recorded();
        $grace = $this->principal($this->student());
        $graceCourse = app(Workspaces::class)->create($grace, ['name' => 'Chemistry'])->id;
        $graceModule = app(Modules::class)->create($grace, $graceCourse, ['title' => 'Acids'])->id;
        $theirs = app(Files::class)->upload($grace, 'module', $graceModule, $this->temp('Secret slides'), 'Theirs.txt')->id;

        $this->page()->set('checkFiles', [$theirs])->call('check')->assertSee('Tick the files the teacher used');

        $this->assertSame(0, DB::table('engine_jobs')->where('kind', 'class_check')->count());
    }
}
