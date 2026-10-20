<?php

namespace Tests\Feature\Platform;

use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Unavailable;
use App\Platform\Files\FileCipher;
use App\Platform\Files\PrivateFiles;
use App\Platform\Files\TempWorkspace;
use App\Platform\Files\UploadTemps;
use App\Platform\Ids;
use App\Study\FilePreviews;
use App\Study\Files;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

class PrivateFilesTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function write(LearnerScope $scope, string $path, string $bytes): void
    {
        $input = fopen($this->temp($bytes), 'rb');
        try {
            PrivateFiles::write($scope, $path, $input);
        } finally {
            fclose($input);
        }
    }

    public function test_all_s3a_artifact_kinds_are_ciphertext_with_independent_identities_and_safe_replacement(): void
    {
        $scope = LearnerScope::of($this->principal($this->student()));
        $other = LearnerScope::of($this->principal($this->student()));
        $marker = 'private-'.bin2hex(random_bytes(16));
        $id = Ids::new();
        foreach (['files/'.$id, 'note-images/'.$id.'.png', 'note-images/'.$id.'.jpg', 'note-images/'.$id.'.webp', 'note-images/'.$id.'.gif', 'previews/'.$id.'.pdf', 'previews/'.$id.'.docx', 'previews/'.$id.'.pptx', 'previews/'.$id.'.xlsx'] as $suffix) {
            $path = 'learners/'.$scope->learnerId.'/'.$suffix;
            $this->write($scope, $path, $marker);
            $raw = PrivateFiles::disk($path)->get($path);
            $this->assertStringNotContainsString($marker, $raw);
            $this->assertStringNotContainsString(hash('sha256', $marker, true), $raw);
            $this->assertSame($marker, PrivateFiles::bytes($scope, $path, 1024));
            $this->assertThrows(fn () => PrivateFiles::bytes($other, $path, 1024), RuntimeException::class);
            $this->assertThrows(fn () => PrivateFiles::bytes($scope, $path, 1), RuntimeException::class);
            $this->write($scope, $path, $marker.' changed');
            $this->assertNotSame($raw, PrivateFiles::disk($path)->get($path));
            $this->assertSame($marker.' changed', PrivateFiles::bytes($scope, $path, 1024));
            $this->assertSame([], array_values(array_filter(PrivateFiles::disk($path)->allFiles('learners'), fn ($key) => str_contains($key, '.writing-'))));
        }
        $this->assertSame(3, count(PrivateFiles::S3A_BACKFILL));
        $this->assertNotSame($scope->key('content'), $scope->key('files'));
        $this->assertNotSame($scope->key('files'), $other->key('files'));
        $this->assertThrows(fn () => PrivateFiles::read($scope, 'learners/'.$scope->learnerId.'/files/../../outside'), RuntimeException::class);
    }

    public function test_download_ranges_seek_across_frames_and_keep_plaintext_lengths_and_authorization(): void
    {
        $user = $this->student();
        $by = $this->principal($user);
        $scope = LearnerScope::of($by);
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Course']);
        $bytes = str_repeat('abcde', 110000).'tail';
        $file = app(Files::class)->upload($by, 'workspace', $workspace->id, $this->temp($bytes), 'Reading.txt');
        $this->assertSame(strlen($bytes), $file->size);
        $this->assertNotSame(hash('sha256', $bytes), $file->sha256);
        $url = route('files.content', $file->id);
        $full = $this->actingAs($user)->get($url)->assertOk()->assertHeader('Content-Length', (string) strlen($bytes))->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame($bytes, $full->streamedContent());
        $this->assertStringContainsString('no-store', $full->headers->get('Cache-Control'));
        $etag = $full->headers->get('ETag');
        foreach ([
            'bytes=0-0' => [0, 1],
            'bytes=262140-262150' => [262140, 11],
            'bytes=549995-' => [549995, strlen($bytes) - 549995],
            'bytes=-4' => [strlen($bytes) - 4, 4],
            'bytes=550000-999999' => [550000, 4],
        ] as $range => [$start, $length]) {
            $response = $this->get($url, ['Range' => $range])->assertStatus(206)
                ->assertHeader('Content-Length', (string) $length)
                ->assertHeader('Content-Range', 'bytes '.$start.'-'.($start + $length - 1).'/'.strlen($bytes));
            $this->assertSame(substr($bytes, $start, $length), $response->streamedContent());
        }
        foreach (['bytes=999999-', 'bytes=2-1', 'bytes=-0', 'bytes=0-1,4-5', 'invalid'] as $range) {
            $this->get($url, ['Range' => $range])->assertStatus(416)->assertHeader('Content-Range', 'bytes */'.strlen($bytes));
        }
        $this->head($url)->assertOk()->assertHeader('Content-Length', (string) strlen($bytes));
        $this->get($url, ['Range' => 'bytes=0-1', 'If-Range' => '"old"'])->assertOk();
        $this->get($url, ['Range' => 'bytes=0-1', 'If-Range' => $etag])->assertStatus(206)->assertStreamedContent('ab');
        $this->actingAs($this->student())->get($url)->assertNotFound();
        $this->actingAs($user);
        app(Files::class)->trash($by, $file->id);
        $this->get($url)->assertStatus(410);
        app(Files::class)->restore($by, $file->id);
        $this->get($url)->assertOk()->assertStreamedContent($bytes);
        $this->assertSame($bytes, app(Files::class)->textPreview($by, $file->id, strlen($bytes)));
        $rawKey = LearnerTables::query($scope, 'files')->where('id', $file->id)->value('storage_key');
        $this->assertStringNotContainsString(substr($bytes, 0, 20), Storage::disk('local')->get($rawKey));
    }

    public function test_note_images_and_export_streams_decrypt_and_remove_processing_temps(): void
    {
        $user = $this->student();
        $by = $this->principal($user);
        $image = $this->image();
        $upload = UploadedFile::fake()->createWithContent('cell.png', $image);
        $result = $this->actingAs($user)->postJson(route('api.v1.notes.images.store'), ['image' => $upload])->assertCreated();
        $imageId = $result->json('id');
        $path = 'learners/'.$by->learnerId.'/note-images/'.$imageId.'.png';
        $this->assertStringStartsWith(FileCipher::MAGIC, Storage::disk('local')->get($path));
        $this->get($result->json('url'))->assertOk()->assertStreamedContent($image);
        $this->actingAs($this->student())->get($result->json('url'))->assertNotFound();
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Course']);
        $note = app(Notes::class)->create($by, 'workspace', $workspace->id, 'Private export');
        $response = $this->actingAs($user)->get(route('workspaces.notes.export', [$workspace->id, $note->id, 'md']))->assertOk();
        $work = glob(config('vistud.files.temp_root').'/work-*');
        $this->assertCount(1, $work);
        $response->assertStreamedContent("# Private export\n");
        $this->assertDirectoryDoesNotExist($work[0]);
    }

    public function test_temporary_decryption_and_failed_upload_callbacks_clean_up_and_pruning_respects_leases(): void
    {
        $scope = LearnerScope::of($this->principal($this->student()));
        $key = 'learners/'.$scope->learnerId.'/files/'.Ids::new();
        $this->write($scope, $key, 'Synthetic private bytes');
        $temp = PrivateFiles::temporary($scope, $key);
        $this->assertSame('Synthetic private bytes', file_get_contents($temp->path.'/input'));
        touch($temp->path.'/.lease', time() - 7200);
        $this->assertSame(0, TempWorkspace::prune());
        $temp->close();
        $this->assertDirectoryDoesNotExist($temp->path);
        $root = config('vistud.files.temp_root');
        $abandoned = $root.'/work-'.bin2hex(random_bytes(16));
        mkdir($abandoned);
        file_put_contents($abandoned.'/.lease', '');
        file_put_contents($abandoned.'/input', 'Synthetic abandoned private bytes');
        touch($abandoned.'/.lease', time() - 7200);
        $this->assertSame(1, TempWorkspace::prune());
        $this->assertDirectoryDoesNotExist($abandoned);
        $upload = UploadedFile::fake()->createWithContent('upload.txt', 'Synthetic upload');
        $path = $upload->getRealPath();
        $this->assertThrows(fn () => UploadTemps::using($upload, fn () => throw new RuntimeException('synthetic failure')), RuntimeException::class);
        $this->assertFileDoesNotExist($path);
        $this->assertSame([], glob($root.'/work-*'));
    }

    public function test_missing_keys_and_corrupt_files_never_fall_back_to_plaintext(): void
    {
        $by = $this->principal($this->student());
        $scope = LearnerScope::of($by);
        $path = 'learners/'.$scope->learnerId.'/files/'.Ids::new();
        Storage::disk('local')->put($path, 'legacy private text');
        $this->assertThrows(fn () => PrivateFiles::read($scope, $path), RuntimeException::class);
        $this->write($scope, $path, 'Synthetic encrypted content');
        $raw = Storage::disk('local')->get($path);
        Storage::disk('local')->put($path, substr($raw, 0, -1));
        $this->assertThrows(fn () => PrivateFiles::temporary($scope, $path), RuntimeException::class);
        $this->assertSame([], glob(config('vistud.files.temp_root').'/work-*'));
        DB::connection('mysql_keys')->table('learner_keys')->where('learner_id', $scope->learnerId)->delete();
        app()->forgetScopedInstances();
        $this->assertThrows(fn () => PrivateFiles::read($scope, $path), Unavailable::class);
    }

    public function test_corrupt_selected_http_bytes_are_refused_before_streaming_and_failed_writes_preserve_the_old_object(): void
    {
        $user = $this->student();
        $by = $this->principal($user);
        $scope = LearnerScope::of($by);
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Course']);
        $file = app(Files::class)->upload($by, 'workspace', $workspace->id, $this->temp('Synthetic private bytes'), 'Reading.txt');
        [, $path] = app(Files::class)->content($by, $file->id);
        $raw = Storage::disk('local')->get($path);
        $bad = $raw;
        $bad[FileCipher::HEADER + 15] = chr(ord($bad[FileCipher::HEADER + 15]) ^ 1);
        Storage::disk('local')->put($path, $bad);
        $this->actingAs($user)->withoutExceptionHandling();
        $this->assertThrows(fn () => $this->get(route('files.content', $file->id), ['Range' => 'bytes=0-5']), RuntimeException::class);
        Storage::disk('local')->put($path, $raw);
        DB::connection('mysql_keys')->table('learner_keys')->where('learner_id', $scope->learnerId)->delete();
        app()->forgetScopedInstances();
        $this->assertThrows(fn () => $this->write($scope, $path, 'Replacement'), Unavailable::class);
        $this->assertSame($raw, Storage::disk('local')->get($path));
        $this->assertSame([], array_values(array_filter(Storage::disk('local')->allFiles('learners'), fn ($key) => str_contains($key, '.writing-'))));
    }

    public function test_expired_livewire_uploads_are_pruned_and_legacy_preview_diagnostics_are_never_printed(): void
    {
        $disk = Storage::disk('temporary');
        $disk->put('livewire-tmp/old-upload', 'Synthetic old upload');
        $disk->put('livewire-tmp/new-upload', 'Synthetic fresh upload');
        touch($disk->path('livewire-tmp/old-upload'), time() - 7200);
        $this->artisan('vistud:files:prune-temps')->expectsOutputToContain('Removed 1 expired temporary uploads.')->assertSuccessful();
        $disk->assertMissing('livewire-tmp/old-upload');
        $disk->assertExists('livewire-tmp/new-upload');
        $scope = LearnerScope::of($this->principal($this->student()));
        $id = Ids::new();
        $key = 'learners/'.$scope->learnerId.'/files/'.$id;
        $preview = 'learners/'.$scope->learnerId.'/previews/'.$id.'.pdf';
        Storage::disk('local')->put($preview.'.failed', 'Synthetic private tool echo');
        $this->assertSame([$preview.'.failed' => 'conversion_failed'], FilePreviews::failures());
        FilePreviews::forget($key);
        Storage::disk('local')->assertMissing($preview.'.failed');
        Storage::disk('derived')->assertMissing($preview.'.failed');
    }
}
