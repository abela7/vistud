<?php

namespace Tests\Feature\Platform;

use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Platform\Files\FileCipher;
use App\Platform\Files\FileConversion;
use App\Platform\Files\PrivateFileResponse;
use App\Platform\Files\PrivateFiles;
use App\Platform\Ids;
use App\Study\ClassRecordings;
use App\Study\ClassSessions;
use App\Study\FileDigests;
use App\Study\Files;
use App\Study\FileTexts;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

class EncryptedFilesTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['vistud.files.office' => 'none']);
    }

    private function learner(): array
    {
        $user = $this->student();
        $by = $this->principal($user);
        $scope = LearnerScope::of($by);
        $course = app(Workspaces::class)->create($by, ['name' => 'Synthetic course']);

        return [$user, $by, $scope, $course];
    }

    public function test_a_damaged_later_frame_ends_the_stream_without_releasing_its_bytes(): void
    {
        [, , $scope] = $this->learner();
        $path = 'learners/'.$scope->learnerId.'/files/'.Ids::new();
        PrivateFiles::writeChunks($scope, $path, [str_repeat('a', FileCipher::CHUNK), str_repeat('secret-later-', 30000)]);
        $raw = Files::disk()->get($path);
        $at = FileCipher::HEADER + FileCipher::CHUNK + 28 + 15;
        $raw[$at] = chr(ord($raw[$at]) ^ 1);
        Files::disk()->put($path, $raw);
        $response = PrivateFileResponse::make(Request::create('/file'), PrivateFiles::read($scope, $path), 'audio.webm', []);
        $this->assertSame(200, $response->getStatusCode());
        ob_start();
        try {
            $response->sendContent();
            $this->fail('Damaged frame was accepted.');
        } catch (RuntimeException) {
            $sent = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertSame(str_repeat('a', FileCipher::CHUNK), $sent);
        $this->assertStringNotContainsString('secret-later-', $sent);
    }

    public function test_recording_pieces_are_encrypted_idempotent_and_bound_to_their_positions(): void
    {
        [$user, $by, $scope, $course] = $this->learner();
        $module = app(Modules::class)->create($by, $course->id, ['title' => 'Week']);
        $class = app(ClassSessions::class)->create($by, $course->id, $module->id, ['title' => 'Class']);
        $recordings = app(ClassRecordings::class);
        $recordings->allow($by, $class->id);
        $part = $recordings->start($by, $class->id);
        $a = str_repeat('a', FileCipher::CHUNK + 7);
        $b = str_repeat('b', 50);
        $recordings->accept($by, $class->id, $part->id, 1, $this->temp($a), 'audio/webm');
        $piece = $part->path.'.chunks/1';
        $original = Files::disk()->get($piece);
        $this->assertStringStartsWith(FileCipher::MAGIC, $original);
        $this->assertStringNotContainsString(str_repeat('a', 50), $original);
        $recordings->accept($by, $class->id, $part->id, 1, $this->temp('different'), 'audio/webm');
        $this->assertSame($original, Files::disk()->get($piece));
        $recordings->accept($by, $class->id, $part->id, 2, $this->temp($b), 'audio/webm');
        $second = Files::disk()->get($part->path.'.chunks/2');
        Files::disk()->put($part->path.'.chunks/2', $original);
        $this->assertThrows(fn () => $recordings->finish($by, $class->id, $part->id, 10), RuntimeException::class);
        Files::disk()->assertMissing($part->path);
        $this->assertNull($recordings->find($by, $class->id, $part->id)->finishedAt);
        Files::disk()->put($part->path.'.chunks/2', $second);
        $done = $recordings->finish($by, $class->id, $part->id, 10);
        $this->assertSame(strlen($a.$b), $done->bytes);
        $this->assertSame($a.$b, PrivateFiles::bytes($scope, $done->path, strlen($a.$b)));
        $url = route('workspaces.modules.classes.audio', [$course->id, $module->id, $class->id, $part->id]);
        $this->actingAs($user)->get($url, ['Range' => 'bytes=262140-262160'])->assertStatus(206)->assertStreamedContent(substr($a.$b, 262140, 21));
    }

    public function test_extracted_text_keeps_the_existing_json_and_is_ciphertext_on_disk(): void
    {
        [, $by, $scope, $course] = $this->learner();
        $file = app(Files::class)->upload($by, 'workspace', $course->id, $this->temp('Synthetic private study marker'), 'Reading.txt');
        $text = app(FileTexts::class)->of($by, $file->id);
        $path = 'learners/'.$scope->learnerId.'/texts/'.$file->id.'.json';
        $this->assertStringNotContainsString('Synthetic private study marker', Files::disk()->get($path));
        $json = json_decode(PrivateFiles::bytes($scope, $path, 10000), true);
        $this->assertSame(3, $json['v']);
        $this->assertSame($text->pages, $json['pages']);
        $this->assertSame($text->pages, app(FileTexts::class)->of($by, $file->id)->pages);
    }

    public function test_backfill_counts_converts_resumes_verifies_and_rolls_back_both_halves(): void
    {
        [, $by, $scope, $course] = $this->learner();
        $file = app(Files::class)->upload($by, 'workspace', $course->id, $this->temp('Synthetic original upload'), 'Reading.txt');
        $digest = app(FileDigests::class)->keep($by, $file->id, ['summary' => 'Summary', 'outline' => [], 'topics' => [], 'language' => null], 1, 25, null);
        $path = 'learners/'.$scope->learnerId.'/files/'.$file->id;
        $text = 'learners/'.$scope->learnerId.'/texts/'.$file->id.'.json';
        $preview = 'learners/'.$scope->learnerId.'/previews/'.$file->id.'.pdf';
        $image = 'learners/'.$scope->learnerId.'/note-images/'.Ids::new().'.png';
        Files::disk()->put($path, 'Synthetic original upload');
        Files::disk()->put($text, '{"v":3,"pages":["Synthetic original upload"],"unit":"part"}');
        Files::disk()->put($preview, '%PDF-Synthetic preview');
        Files::disk()->put($image, $this->image());
        LearnerTables::query($scope, 'files')->where('id', $file->id)->update(['sha256' => hash('sha256', 'Synthetic original upload')]);
        LearnerTables::query($scope, 'file_digests')->where('id', $digest->id)->update(['content_hash' => hash('sha256', 'Synthetic original upload')]);
        app(Files::class)->trash($by, $file->id);
        $this->artisan('vistud:encrypt:files', ['--dry-run' => true])->expectsOutputToContain('legacy: 4')->assertSuccessful();
        $this->assertSame('Synthetic original upload', Files::disk()->get($path));
        $this->assertSame([], Files::disk()->allFiles('learners/'.$scope->learnerId.'/file-conversion'));
        $this->artisan('vistud:encrypt:files', ['--limit' => 1])->assertFailed();
        $this->artisan('vistud:encrypt:files')->assertSuccessful();
        Files::disk()->assertMissing($preview);
        Storage::disk('derived')->assertExists($preview);
        foreach ([$path, $text, $preview, $image] as $object) {
            $this->assertStringStartsWith(FileCipher::MAGIC, PrivateFiles::disk($object)->get($object));
        }
        $encrypted = Files::disk()->get($path);
        $this->artisan('vistud:encrypt:files')->expectsOutputToContain('changed: 0')->assertSuccessful();
        $this->assertSame($encrypted, Files::disk()->get($path));
        $this->artisan('vistud:encrypt:files', ['--verify' => true])->assertSuccessful();
        app(Files::class)->restore($by, $file->id);
        $this->assertSame('Summary', app(FileDigests::class)->find($by, $file->id)->summary);
        $this->artisan('vistud:encrypt:files', ['--decrypt' => true])->assertSuccessful();
        $this->assertSame('Synthetic original upload', Files::disk()->get($path));
        $this->assertSame('%PDF-Synthetic preview', Files::disk()->get($preview));
        Storage::disk('derived')->assertMissing($preview);
        $this->assertSame(hash('sha256', 'Synthetic original upload'), LearnerTables::query($scope, 'files')->where('id', $file->id)->value('sha256'));
        $this->artisan('vistud:encrypt:files')->assertSuccessful();
        $this->artisan('vistud:encrypt:files', ['--verify' => true])->assertSuccessful();
    }

    public function test_backfill_refuses_corruption_missing_keys_missing_sources_orphans_and_conflicting_previews(): void
    {
        [, $by, $scope, $course] = $this->learner();
        $file = app(Files::class)->upload($by, 'workspace', $course->id, $this->temp('Original'), 'Reading.txt');
        $path = 'learners/'.$scope->learnerId.'/files/'.$file->id;
        $original = Files::disk()->get($path);
        Files::disk()->put($path, substr($original, 0, -1));
        $this->artisan('vistud:encrypt:files')->assertFailed();
        $this->assertSame(substr($original, 0, -1), Files::disk()->get($path));
        Files::disk()->put($path, $original);
        $preview = 'learners/'.$scope->learnerId.'/previews/'.$file->id.'.pdf';
        Files::disk()->put($preview, 'First');
        PrivateFiles::writeChunks($scope, $preview, ['Different']);
        $this->artisan('vistud:encrypt:files', ['--dry-run' => true])->expectsOutputToContain('conflict: 1')->assertFailed();
        Files::disk()->delete($preview);
        Storage::disk('derived')->delete($preview);
        $orphan = 'learners/'.$scope->learnerId.'/files/'.Ids::new();
        Files::disk()->put($orphan, 'Orphan marker');
        $this->artisan('vistud:encrypt:files')->assertFailed();
        Files::disk()->delete($orphan);
        Files::disk()->delete($path);
        $this->artisan('vistud:encrypt:files', ['--verify' => true])->expectsOutputToContain('missing: 1')->assertFailed();
        Files::disk()->put($path, $original);
        DB::connection('mysql_keys')->table('learner_keys')->where('learner_id', $scope->learnerId)->delete();
        app()->forgetScopedInstances();
        $this->artisan('vistud:encrypt:files')->assertFailed();
        $this->assertSame($original, Files::disk()->get($path));
    }

    public function test_a_receipt_detects_a_destroyed_header_and_the_converter_preserves_a_conflicting_target(): void
    {
        [, $by, $scope, $course] = $this->learner();
        $file = app(Files::class)->upload($by, 'workspace', $course->id, $this->temp('Original'), 'Reading.txt');
        $path = 'learners/'.$scope->learnerId.'/files/'.$file->id;
        $this->artisan('vistud:encrypt:files')->assertSuccessful();
        $raw = Files::disk()->get($path);
        $raw[0] = 'X';
        Files::disk()->put($path, $raw);
        $this->artisan('vistud:encrypt:files')->assertFailed();
        $this->assertSame($raw, Files::disk()->get($path));
        $source = $this->temp('First');
        $target = $this->temp('Different');
        $this->assertThrows(fn () => FileConversion::convert($scope, $path, $source, $target, false), RuntimeException::class);
        $this->assertSame('First', file_get_contents($source));
        $this->assertSame('Different', file_get_contents($target));
    }

    public function test_legacy_joined_and_unfinished_recordings_and_old_diagnostics_survive_conversion_and_rollback(): void
    {
        [, $by, $scope, $course] = $this->learner();
        $module = app(Modules::class)->create($by, $course->id, ['title' => 'Week']);
        $class = app(ClassSessions::class)->create($by, $course->id, $module->id, ['title' => 'Class']);
        $recordings = app(ClassRecordings::class);
        $recordings->allow($by, $class->id);
        $part = $recordings->start($by, $class->id);
        $recordings->accept($by, $class->id, $part->id, 1, $this->temp('first'), 'audio/webm');
        $recordings->finish($by, $class->id, $part->id, 5);
        Files::disk()->put($part->path, 'first');
        LearnerTables::query($scope, 'class_recordings')->where('id', $part->id)->update(['sha256' => hash('sha256', 'first')]);
        $open = $recordings->start($by, $class->id);
        $recordings->accept($by, $class->id, $open->id, 1, $this->temp('second'), 'audio/webm');
        Files::disk()->put($open->path.'.chunks/1', 'second');
        $legacy = storage_path('app/transcribe-try/'.$class->id.'-synthetic');
        mkdir($legacy, 0700, true);
        file_put_contents($legacy.'/transcript.md', 'Synthetic old transcript');
        file_put_contents($legacy.'/notes.md', 'Synthetic old notes');
        try {
            $this->artisan('vistud:encrypt:files', ['--dry-run' => true])->assertSuccessful();
            $this->assertSame('Synthetic old transcript', file_get_contents($legacy.'/transcript.md'));
            $this->artisan('vistud:encrypt:files')->assertSuccessful();
            $this->assertSame('first', PrivateFiles::bytes($scope, $part->path, 100));
            $this->assertSame('second', PrivateFiles::bytes($scope, $open->path.'.chunks/1', 100));
            $this->assertFileDoesNotExist($legacy.'/transcript.md');
            $outputs = Files::disk()->allFiles('learners/'.$scope->learnerId.'/transcribe-try/'.$class->id);
            $this->assertCount(2, $outputs);
            $this->artisan('vistud:encrypt:files', ['--verify' => true])->assertSuccessful();
            $this->artisan('vistud:encrypt:files', ['--decrypt' => true])->assertSuccessful();
            $this->assertSame('first', Files::disk()->get($part->path));
            $this->assertSame('second', Files::disk()->get($open->path.'.chunks/1'));
            $this->assertStringContainsString('Synthetic old', Files::disk()->get($outputs[0]));
            $this->artisan('vistud:encrypt:files')->assertSuccessful();
            $this->assertNotNull($recordings->recover($by, $class->id, $open->id)->finishedAt);
        } finally {
            File::deleteDirectory($legacy);
        }
    }

    public function test_stages_are_reconciled_only_with_a_verified_canonical_and_readonly_modes_do_not_clean_them(): void
    {
        [, $by, $scope, $course] = $this->learner();
        $file = app(Files::class)->upload($by, 'workspace', $course->id, $this->temp('Original'), 'Reading.txt');
        $path = 'learners/'.$scope->learnerId.'/files/'.$file->id;
        $stage = $path.'.writing-'.str_repeat('a', 32);
        Files::disk()->put($stage, 'Interrupted stage');
        $this->artisan('vistud:encrypt:files', ['--dry-run' => true])->assertFailed();
        Files::disk()->assertExists($stage);
        $this->artisan('vistud:encrypt:files')->assertSuccessful();
        Files::disk()->assertMissing($stage);
        Files::disk()->put($stage, 'Recoverable stage');
        Files::disk()->delete($path);
        $this->artisan('vistud:encrypt:files')->assertFailed();
        Files::disk()->assertExists($stage);
    }

    public function test_unknown_learner_and_nonmaintenance_execution_are_refused(): void
    {
        $this->artisan('vistud:encrypt:files', ['--learner' => 'missing'])->assertFailed();
        $this->app['env'] = 'production';
        try {
            $this->artisan('vistud:encrypt:files')->expectsOutputToContain('Maintenance')->assertFailed();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_published_bytes_with_old_metadata_resume_and_old_temps_need_explicit_purge(): void
    {
        [, $by, $scope, $course] = $this->learner();
        $file = app(Files::class)->upload($by, 'workspace', $course->id, $this->temp('Original'), 'Reading.txt');
        $path = 'learners/'.$scope->learnerId.'/files/'.$file->id;
        LearnerTables::query($scope, 'files')->where('id', $file->id)->update(['sha256' => hash('sha256', 'Original')]);
        // Crash after publication but before the database fingerprint commits.
        $this->artisan('vistud:encrypt:files', ['--verify' => true])->assertFailed();
        $before = Files::disk()->get($path);
        $this->artisan('vistud:encrypt:files')->assertSuccessful();
        $this->assertSame($before, Files::disk()->get($path));
        $this->artisan('vistud:encrypt:files', ['--verify' => true])->assertSuccessful();
        Files::disk()->put('previews-work/old/input', 'Synthetic plaintext temp');
        $this->artisan('vistud:encrypt:files', ['--dry-run' => true])->expectsOutputToContain('temps: 1')->assertFailed();
        Files::disk()->assertExists('previews-work/old/input');
        $this->artisan('vistud:encrypt:files', ['--purge-temp-confirmed' => true])->assertSuccessful();
        Files::disk()->assertMissing('previews-work/old/input');
    }

    public function test_missing_note_images_and_oversized_receipts_are_reported_without_changing_data(): void
    {
        [, $by, $scope, $course] = $this->learner();
        $file = app(Files::class)->upload($by, 'workspace', $course->id, $this->temp('Original'), 'Reading.txt');
        $this->artisan('vistud:encrypt:files')->assertSuccessful();
        $receipts = Files::disk()->allFiles('learners/'.$scope->learnerId.'/file-conversion');
        $this->assertCount(1, $receipts);
        Files::disk()->put($receipts[0], str_repeat('x', 8193));
        $this->artisan('vistud:encrypt:files', ['--verify' => true])->assertFailed();
        Files::disk()->delete($receipts[0]);
        $note = app(Notes::class)->create($by, 'workspace', $course->id, 'Image');
        app(Notes::class)->append($by, $note->id, [['type' => 'image', 'attrs' => ['src' => '/notes/images/'.Ids::new(), 'alt' => 'Synthetic image']]]);
        $this->artisan('vistud:encrypt:files', ['--dry-run' => true])->expectsOutputToContain('missing:')->assertFailed();
        $this->assertSame('Original', PrivateFiles::bytes($scope, 'learners/'.$scope->learnerId.'/files/'.$file->id, 100));
    }
}
