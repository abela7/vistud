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
use App\Study\ClassNote;
use App\Study\ClassRecordings;
use App\Study\ClassSessions;
use App\Study\Modules;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * A note written while the class is being recorded: Note starts it where the recording is, Save note ends it, and it is
 * kept against those minutes at once, before the recording has finished, then answered from what was said.
 */
class ClassLiveNoteTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $course;

    private string $module;

    private string $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->course = app(Workspaces::class)->create($this->by, ['name' => 'Biology'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->course, ['title' => 'Cells'])->id;
        $this->class = app(ClassSessions::class)->create($this->by, $this->course, $this->module, ['title' => 'Lecture · 19 Oct 2026', 'held_on' => '2026-10-19'])->id;
        app(ClassRecordings::class)->allow($this->by, $this->class);
    }

    private function page()
    {
        return Livewire::actingAs($this->ada)->test(ClassRecorder::class, ['workspaceId' => $this->course, 'moduleId' => $this->module, 'classId' => $this->class]);
    }

    public function test_the_recorder_has_a_note_button_that_takes_the_minute_for_you(): void
    {
        $this->page()->assertSee('Note')->assertSee('Save note')->assertSee('Recording this class', false)->assertSeeHtml('data-offset="0"');
    }

    public function test_a_note_is_kept_against_its_minutes_before_the_recording_has_finished(): void
    {
        $this->page()->call('addLiveNote', 754, 801, "Why does the nucleus divide before the cell?\nAsk next week.")->assertHasNoErrors();

        $marks = app(ClassNote::class)->read($this->by, $this->class);
        $this->assertCount(1, $marks);
        $this->assertSame(['12:34', '13:21', 'Why does the nucleus divide before the cell? Ask next week.'], [$marks[0]['from'], $marks[0]['until'], $marks[0]['about']]);
        $this->assertStringContainsString('Ask next week.', $marks[0]['body']);
    }

    public function test_notes_pile_up_in_one_class_note_and_an_empty_note_is_not_kept(): void
    {
        $page = $this->page();
        $page->call('addLiveNote', 60, 90, 'First')->call('addLiveNote', 3700, 3710, 'Later, past the hour')->call('addLiveNote', 100, 110, '   ');

        $marks = app(ClassNote::class)->read($this->by, $this->class);
        $this->assertSame(['First', 'Later, past the hour'], array_column($marks, 'about'));
        $this->assertSame('1:01:40', $marks[1]['from']);
    }

    public function test_a_note_that_ends_where_it_starts_still_has_a_length(): void
    {
        $this->page()->call('addLiveNote', 200, 200, 'Quick');

        $this->assertSame(['3:20', '3:21'], [app(ClassNote::class)->read($this->by, $this->class)[0]['from'], app(ClassNote::class)->read($this->by, $this->class)[0]['until']]);
    }

    public function test_a_second_take_starts_its_notes_where_the_first_ended(): void
    {
        $recordings = app(ClassRecordings::class);
        $part = $recordings->start($this->by, $this->class);
        $recordings->accept($this->by, $this->class, $part->id, 1, $this->temp('webm bytes'), 'audio/webm');
        $recordings->finish($this->by, $this->class, $part->id, 1500);

        $this->page()->assertSeeHtml('data-offset="1500"');
    }

    public function test_the_lecture_notes_answer_what_the_student_wrote_at_its_minutes(): void
    {
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $engine = new Fake;
        $this->app->instance(Engine::class, $engine);
        $this->ownAiKey($this->by);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
        $recordings = app(ClassRecordings::class);
        $part = $recordings->start($this->by, $this->class);
        $recordings->accept($this->by, $this->class, $part->id, 1, $this->temp('webm bytes'), 'audio/webm');
        $recordings->finish($this->by, $this->class, $part->id, 1200);
        $this->app->instance(AudioPieces::class, new class extends AudioPieces
        {
            public function split(string $file, int $seconds, string $directory): array
            {
                File::ensureDirectoryExists($directory);
                File::put($directory.'/piece-000.mp3', 'first');

                return [$directory.'/piece-000.mp3'];
            }
        });
        $page = $this->page();
        $page->call('addLiveNote', 754, 801, 'Why does the nucleus divide first?');
        $engine->will(
            Fake::says('The nucleus divides in mitosis.', 1000, 'google/gemini-2.5-flash'),
            function (Request $request) {
                $text = (string) $request->messages[0]['content'];
                $this->assertStringContainsString('12:34–13:21: Why does the nucleus divide first?', $text);
                $this->assertStringContainsString('Your note (mm:ss)', $request->system);

                return Fake::says('## 00:00–10:00 Notes', 1000);
            },
        );

        $page->call('transcribe')->assertSee('Lecture notes');
    }

    public function test_another_student_cannot_add_a_note_to_this_class(): void
    {
        $grace = $this->student();

        try {
            Livewire::actingAs($grace)->test(ClassRecorder::class, ['workspaceId' => $this->course, 'moduleId' => $this->module, 'classId' => $this->class])->call('addLiveNote', 10, 20, 'Mine');
            $this->fail('Another student opened the class.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Not found', $e->getMessage());
        }
        $this->assertSame([], app(ClassNote::class)->read($this->by, $this->class));
    }

    private function record(): string
    {
        $recordings = app(ClassRecordings::class);
        $part = $recordings->start($this->by, $this->class);
        $recordings->accept($this->by, $this->class, $part->id, 1, $this->temp('webm bytes'), 'audio/webm');

        return $recordings->finish($this->by, $this->class, $part->id, 600)->path;
    }

    public function test_the_recording_can_be_deleted_for_good_and_the_class_and_notes_stay(): void
    {
        $path = $this->record();
        $page = $this->page();
        $page->call('addLiveNote', 60, 90, 'Keep me');
        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->page()->assertSee('Delete the recording')->call('deleteRecording');

        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame(0, DB::table('class_recordings')->count());
        $class = app(ClassSessions::class)->find($this->by, $this->class);
        $this->assertSame([0, ClassSessions::NEW], [$class->durationSeconds, $class->status]);
        $this->assertSame(['Keep me'], array_column(app(ClassNote::class)->read($this->by, $this->class), 'about'));
        $this->page()->assertSee('Record');
    }

    public function test_the_class_can_be_deleted_and_another_student_cannot_delete_it(): void
    {
        $path = $this->record();
        $grace = $this->student();
        try {
            Livewire::actingAs($grace)->test(ClassRecorder::class, ['workspaceId' => $this->course, 'moduleId' => $this->module, 'classId' => $this->class])->call('deleteClass');
            $this->fail('Another student opened the class.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Not found', $e->getMessage());
        }
        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->page()->call('deleteClass')->assertRedirect(route('workspaces.modules.classes', [$this->course, $this->module]));

        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertSame([], app(ClassSessions::class)->forModule($this->by, $this->course, $this->module));
    }

    public function test_a_class_with_nothing_recorded_can_still_be_deleted(): void
    {
        $this->page()->assertSee('Delete this class')->assertDontSee('Delete the recording')->call('deleteClass');

        $this->assertSame([], app(ClassSessions::class)->forModule($this->by, $this->course, $this->module));
    }
}
