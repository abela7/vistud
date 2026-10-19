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
 * "Write this class down" on the class page: the recording in pieces, each written down by an audio model, then lecture
 * notes; both kept as notes in the module, and written again as new versions of the same two notes.
 */
class ClassTranscribeTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $course;

    private string $module;

    private string $class;

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
        $this->class = app(ClassSessions::class)->create($this->by, $this->course, $this->module, ['title' => 'Week 3 lecture', 'held_on' => '2026-10-14'])->id;
        $recordings = app(ClassRecordings::class);
        $recordings->allow($this->by, $this->class);
        $part = $recordings->start($this->by, $this->class);
        $recordings->accept($this->by, $this->class, $part->id, 1, $this->temp('webm bytes'), 'audio/webm');
        $recordings->finish($this->by, $this->class, $part->id, 1200);
        // ffmpeg stands in: two pieces.
        $this->app->instance(AudioPieces::class, new class extends AudioPieces
        {
            public function split(string $file, int $seconds, string $directory): array
            {
                File::ensureDirectoryExists($directory);
                File::put($directory.'/piece-000.mp3', 'first');
                File::put($directory.'/piece-001.mp3', 'second');

                return [$directory.'/piece-000.mp3', $directory.'/piece-001.mp3'];
            }
        });
    }

    private function page()
    {
        return Livewire::actingAs($this->ada)->test(ClassRecorder::class, ['workspaceId' => $this->course, 'moduleId' => $this->module, 'classId' => $this->class]);
    }

    private function answers(string $notes = '## 00:00–10:00 Mitosis has four phases.'): void
    {
        $this->engine->will(
            function (Request $request) {
                $this->assertSame('google/gemini-2.5-flash', $request->model);
                $this->assertSame('input_audio', $request->messages[0]['content'][1]['type']);

                return Fake::says('Mitosis has four phases.', 1000, 'google/gemini-2.5-flash');
            },
            Fake::says('Student: Is this on the exam? [unclear]', 1000, 'google/gemini-2.5-flash'),
            function (Request $request) use ($notes) {
                $this->assertStringContainsString('## 10:00–20:00', (string) $request->messages[0]['content']);

                return Fake::says($notes, 1000, 'google/gemini-2.5-flash');
            },
        );
    }

    public function test_the_class_is_written_down_and_its_two_notes_appear_in_the_module(): void
    {
        $this->answers();

        $this->page()->assertSee('Write this class down')->call('transcribe')->assertSee('Lecture notes')->assertSee('Full transcript');

        $class = app(ClassSessions::class)->find($this->by, $this->class);
        $titles = DB::table('notes')->where('module_id', $this->module)->pluck('title')->all();
        $this->assertContains('Transcript · Week 3 lecture', $titles);
        $this->assertContains('Lecture notes · Week 3 lecture', $titles);
        $transcript = (string) DB::table('note_versions')->where('note_id', $class->transcriptNoteId)->orderByDesc('version')->value('doc');
        $this->assertStringContainsString('[unclear]', $transcript);
        $this->assertStringContainsString('Mitosis', (string) DB::table('note_versions')->where('note_id', $class->lectureNotesId)->orderByDesc('version')->value('doc'));
        $this->assertSame(1, DB::table('engine_jobs')->where('kind', 'transcribe_class')->where('status', 'done')->count());
        $this->assertSame(3000, (int) DB::table('engine_jobs')->where('kind', 'transcribe_class')->value('cost_micros'));
    }

    public function test_writing_it_down_again_keeps_the_same_two_notes_with_a_new_version(): void
    {
        $this->answers();
        $this->page()->call('transcribe');
        $first = app(ClassSessions::class)->find($this->by, $this->class);

        $this->answers('## 00:00–10:00 Better notes.');
        $this->page()->assertSee('Write it down again')->call('transcribe');
        $again = app(ClassSessions::class)->find($this->by, $this->class);

        $this->assertSame([$first->transcriptNoteId, $first->lectureNotesId], [$again->transcriptNoteId, $again->lectureNotesId]);
        $this->assertSame(2, DB::table('notes')->where('module_id', $this->module)->where('title', 'like', '%Week 3 lecture')->count());
        $this->assertStringContainsString('Better notes', (string) DB::table('note_versions')->where('note_id', $again->lectureNotesId)->orderByDesc('version')->value('doc'));
    }

    public function test_without_a_key_it_says_where_to_add_one_and_keeps_nothing(): void
    {
        config(['vistud.engine.key' => '']);
        DB::table('engine_settings')->update(['key_encrypted' => null]);

        $this->page()->call('transcribe')->assertSee('Settings, AI');

        $this->assertNull(app(ClassSessions::class)->find($this->by, $this->class)->lectureNotesId);
        $this->assertSame(0, DB::table('notes')->where('title', 'like', 'Lecture notes%')->count());
    }

    public function test_another_student_cannot_write_down_this_class(): void
    {
        $grace = $this->student();

        try {
            Livewire::actingAs($grace)->test(ClassRecorder::class, ['workspaceId' => $this->course, 'moduleId' => $this->module, 'classId' => $this->class])->call('transcribe');
            $this->fail('Another student opened the class.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Not found', $e->getMessage());
        }
        $this->assertSame(0, DB::table('engine_jobs')->count());
    }
}
