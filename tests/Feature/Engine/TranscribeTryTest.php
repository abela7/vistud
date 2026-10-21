<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Request;
use App\Engine\Settings;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Files\FileCipher;
use App\Platform\Files\PrivateFiles;
use App\Study\AudioPieces;
use App\Study\ClassRecordings;
use App\Study\ClassSessions;
use App\Study\Modules;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** vistud:transcribe:try: a class recording in pieces, written down word for word, then lecture notes, with the cost. */
class TranscribeTryTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private Principal $by;

    private string $class;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $ada = $this->student();
        $this->by = $this->principal($ada);
        $this->ownAiKey($this->by);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
        $course = app(Workspaces::class)->create($this->by, ['name' => 'Biology']);
        $cells = app(Modules::class)->create($this->by, $course->id, ['title' => 'Cells']);
        $this->class = app(ClassSessions::class)->create($this->by, $course->id, $cells->id, ['title' => 'Week 3 lecture', 'held_on' => '2026-10-14'])->id;
    }

    private function record(): void
    {
        $recordings = app(ClassRecordings::class);
        $recordings->allow($this->by, $this->class);
        $part = $recordings->start($this->by, $this->class);
        $recordings->accept($this->by, $this->class, $part->id, 1, $this->temp('webm bytes'), 'audio/webm');
        $recordings->finish($this->by, $this->class, $part->id, 1200);
    }

    /** ffmpeg stands in: two pieces of fake mp3. */
    private function pieces(): void
    {
        $this->app->instance(AudioPieces::class, new class extends AudioPieces
        {
            public function split(string $file, int $seconds, string $directory): array
            {
                if (file_get_contents($file) !== 'webm bytes') {
                    throw new \RuntimeException('Transcriber did not receive decrypted recording bytes.');
                }
                File::ensureDirectoryExists($directory);
                File::put($directory.'/piece-000.mp3', 'first');
                File::put($directory.'/piece-001.mp3', 'second');

                return [$directory.'/piece-000.mp3', $directory.'/piece-001.mp3'];
            }
        });
    }

    public function test_each_piece_is_heard_by_the_audio_model_then_the_notes_by_the_reader_and_nothing_else_changes(): void
    {
        $this->record();
        $this->pieces();
        $this->engine->will(
            function (Request $request) {
                $this->assertSame('google/gemini-2.5-flash', $request->model);
                $this->assertStringContainsString('[unclear]', $request->system);
                $this->assertSame(['type' => 'input_audio', 'input_audio' => ['data' => base64_encode('first'), 'format' => 'mp3']], $request->messages[0]['content'][1]);
                $this->assertStringContainsString('Course: Biology. Module: Cells. Class: Week 3 lecture.', $request->messages[0]['content'][0]['text']);

                return Fake::says('Mitosis has four phases.', 4000, 'google/gemini-2.5-flash');
            },
            Fake::says('Student: Is this on the exam? [unclear]', 4000, 'google/gemini-2.5-flash'),
            function (Request $request) {
                $this->assertSame('fake/quick', $request->model);
                $text = (string) $request->messages[0]['content'];
                $this->assertStringContainsString('## 00:00–10:00', $text);
                $this->assertStringContainsString('## 10:00–20:00', $text);
                $this->assertStringContainsString('<material>', $text);

                return Fake::says('## 00:00–10:00 Mitosis has four phases.', 2000);
            },
        );
        $noteBefore = DB::table('class_sessions')->where('id', $this->class)->value('note_id');

        $this->artisan('vistud:transcribe:try', ['class' => $this->class])
            ->expectsOutputToContain('Mitosis has four phases.')
            ->expectsOutputToContain('together: $0.01')
            ->assertSuccessful();

        $this->assertSame(['transcribe_notes', 'transcribe_piece', 'transcribe_piece'], DB::table('engine_jobs')->orderBy('kind')->pluck('kind')->all());
        $this->assertSame($noteBefore, DB::table('class_sessions')->where('id', $this->class)->value('note_id'));
        $scope = LearnerScope::of($this->by);
        $outputs = Storage::disk('local')->allFiles('learners/'.$scope->learnerId.'/transcribe-try/'.$this->class);
        $this->assertCount(2, $outputs);
        foreach ($outputs as $output) {
            $this->assertStringStartsWith(FileCipher::MAGIC, Storage::disk('local')->get($output));
            $this->assertStringNotContainsString('Mitosis', Storage::disk('local')->get($output));
            $this->assertStringContainsString(str_ends_with($output, '.transcript.md') ? '[unclear]' : 'Mitosis', PrivateFiles::bytes($scope, $output, 10000));
        }
        $this->assertSame([], glob(config('vistud.files.temp_root').'/work-*'));
    }

    public function test_a_class_with_no_finished_recording_or_no_ffmpeg_says_so(): void
    {
        $this->artisan('vistud:transcribe:try', ['class' => $this->class])->expectsOutputToContain('no finished recording')->assertFailed();

        $this->record();
        config(['vistud.files.ffmpeg' => 'definitely-not-ffmpeg-here']);
        $this->artisan('vistud:transcribe:try', ['class' => $this->class])->expectsOutputToContain('Install ffmpeg')->assertFailed();
        $this->assertSame(0, DB::table('engine_jobs')->count());
    }

    public function test_another_students_class_is_not_found(): void
    {
        $this->record();
        $grace = $this->student();

        $this->artisan('vistud:transcribe:try', ['class' => $this->class, '--user' => $grace->email])->expectsOutputToContain('Not found')->assertFailed();
    }
}
