<?php

namespace Tests\Feature\Identity;

use App\Identity\Accounts;
use App\Identity\Erasure;
use App\Identity\ErasureStage;
use App\Jobs\EraseLearner;
use App\Jobs\ExtractFileText;
use App\Jobs\MakeFilePreview;
use App\Mcp\Connections;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Forbidden;
use App\Platform\Errors\Unavailable;
use App\Platform\Files\PrivateFiles;
use App\Platform\Files\TempWorkspace;
use App\Platform\Ids;
use App\Study\Files;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Builder regressions only. The independent G3 acceptance drill is separate. */
class ErasureTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    protected function connectionsToTransact(): array
    {
        // The privileged worker has its own PDO and must see committed fixtures.
        return [];
    }

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function fixture(): array
    {
        $user = $this->student();
        $by = $this->principal($user);
        $scope = LearnerScope::of($by);
        $course = app(Workspaces::class)->create($by, ['name' => 'Synthetic private course']);
        $note = app(Notes::class)->create($by, 'workspace', $course->id, 'Synthetic private note');
        $file = app(Files::class)->upload($by, 'workspace', $course->id, $this->temp('Synthetic private source'), 'Reading.txt');

        return [$user, $by, $scope, $course, $note, $file];
    }

    private function request(object $user, Principal $by): object
    {
        app(Accounts::class)->requestDeletion($by, $user->id);

        return DB::table('erasure_requests')->where('user_id', $user->id)->first();
    }

    public function test_request_closes_access_queues_after_delay_and_is_idempotent(): void
    {
        $this->travelTo(now()->startOfSecond());
        [$user, $by, $scope] = $this->fixture();
        $scope->key('content'); // Revocation must evict this request's warmed ring.
        DB::table('sessions')->insert(['id' => 'synthetic-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $request = $this->request($user, $by);
        $this->assertSame('deleted', $user->fresh()->status);
        $this->assertFalse(DB::table('sessions')->where('user_id', $user->id)->exists());
        $this->assertSame($scope->learnerId, $request->learner_ref);
        $this->assertGreaterThanOrEqual(10800, now()->diffInSeconds($request->not_before));
        Queue::assertPushed(EraseLearner::class, fn ($job) => $job->requestId === $request->id && $job->connection === 'database' && $job->afterCommit);
        $this->assertThrows(fn () => $scope->key('content'), Unavailable::class);
        $this->assertFalse(app(Erasure::class)->erase(Principal::system(), $request->id));
        app(Accounts::class)->requestDeletion($by, $user->id);
        Queue::assertPushed(EraseLearner::class, 1);
        $this->assertSame(1, DB::table('erasure_requests')->count());
    }

    public function test_all_registered_tables_files_caches_jobs_tokens_and_identity_are_erased_with_key_last(): void
    {
        $this->app['cache']->setDefaultDriver('database');
        [$user, $by, $scope, , , $file] = $this->fixture();
        [$other, , $otherScope] = $this->fixture();
        $batch = Ids::new();
        DB::table('job_batches')->insert(['id' => $batch, 'name' => 'Shared synthetic batch', 'total_jobs' => 2, 'pending_jobs' => 2, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => serialize([$scope->learnerId, $otherScope->learnerId]), 'created_at' => time()]);
        RateLimiter::hit(md5('mcpmcp:'.$scope->learnerId), 36000);
        $root = 'learners/'.$scope->learnerId.'/';
        foreach (['note-images/'.Ids::new().'.png', 'previews/'.$file->id.'.pdf', 'texts/'.$file->id.'.json', 'classes/'.Ids::new().'/'.Ids::new().'.chunks/1', 'transcribe-try/'.Ids::new().'/'.Ids::new().'.notes.md'] as $suffix) {
            PrivateFiles::writeChunks($scope, $root.$suffix, ['Synthetic private bytes']);
        }
        Files::disk()->put($root.'file-conversion/synthetic.state', 'Synthetic receipt');
        Files::disk()->put($root.'files/'.$file->id.'.writing-'.str_repeat('a', 32), 'Synthetic stage');
        Cache::put('topic-review:'.$scope->learnerId.':gone-folder', 'Synthetic private cache');
        Cache::put('topic-files-out:'.$scope->learnerId.':gone-module', ['Synthetic private cache']);
        Cache::put('engine-trouble:'.$scope->learnerId, 'Synthetic private error');
        Cache::put('topic-review:'.$otherScope->learnerId.':keep', 'Other learner');
        foreach (['jobs', 'failed_jobs'] as $name) {
            $fields = ['queue' => 'default', 'payload' => json_encode(['learnerId' => $scope->learnerId])];
            $fields += $name === 'jobs' ? ['attempts' => 0, 'available_at' => time(), 'created_at' => time()]
                : ['uuid' => Ids::new(), 'connection' => 'database', 'exception' => 'Synthetic private exception', 'failed_at' => now()];
            DB::table($name)->insert($fields);
        }
        $client = Ids::new();
        DB::table('oauth_clients')->insert(['id' => $client, 'name' => 'Shared client', 'redirect_uris' => '[]', 'grant_types' => '[]', 'revoked' => false]);
        $connection = app(Connections::class)->consent($by, $client, 'full');
        LearnerTables::insert($scope, 'mcp_confirmations', ['id' => Ids::new(), 'connection_id' => $connection->id, 'tool' => 'synthetic', 'input' => json_encode(['text' => 'Synthetic private confirmation']), 'input_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);
        LearnerTables::insert($scope, 'mcp_calls', ['id' => Ids::new(), 'connection_id' => $connection->id, 'tool' => 'synthetic', 'outcome' => 'ok', 'created_at' => now()]);
        DB::table('oauth_access_tokens')->insert(['id' => 'synthetic-access', 'user_id' => $user->id, 'client_id' => $client, 'revoked' => false]);
        DB::table('oauth_refresh_tokens')->insert(['id' => 'synthetic-refresh', 'access_token_id' => 'synthetic-access', 'revoked' => false]);
        DB::table('oauth_auth_codes')->insert(['id' => 'synthetic-code', 'user_id' => $user->id, 'client_id' => $client, 'revoked' => false]);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'synthetic', 'created_at' => now()]);
        $request = $this->request($user, $by);
        $this->travel(3)->hours();
        $stages = [];
        Event::listen(ErasureStage::class, function ($event) use (&$stages, $scope) {
            $stages[] = $event->stage;
            $this->assertSame($event->stage !== 'sweep' && $event->stage !== 'complete', LearnerTables::query($scope, 'learner_keys')->exists());
        });
        $this->assertTrue(app(Erasure::class)->erase(Principal::system(), $request->id));
        foreach (LearnerTables::TABLES as $table) {
            $this->assertSame(0, LearnerTables::forErasure(Principal::system(), $scope, $table)->count(), $table);
        }
        $this->assertSame(['access', 'content', 'files', 'key', 'sweep', 'complete'], $stages);
        $this->assertFalse(DB::table('users')->where('id', $user->id)->exists());
        $this->assertFalse(DB::table('learners')->where('id', $scope->learnerId)->exists());
        foreach (['local', 'derived'] as $disk) {
            $this->assertSame([], Storage::disk($disk)->allFiles(rtrim($root, '/')));
        }
        $this->assertNull(Cache::get('topic-review:'.$scope->learnerId.':gone-folder'));
        $this->assertSame('Other learner', Cache::get('topic-review:'.$otherScope->learnerId.':keep'));
        $this->assertSame(0, RateLimiter::attempts(md5('mcpmcp:'.$scope->learnerId)));
        $options = DB::table('job_batches')->where('id', $batch)->value('options');
        $this->assertStringNotContainsString($scope->learnerId, $options);
        $this->assertStringContainsString($otherScope->learnerId, $options);
        $this->assertSame(['00000000-0000-0000-0000-000000000000', $otherScope->learnerId], unserialize($options, ['allowed_classes' => false]));
        $this->assertNotEmpty(Files::disk()->allFiles('learners/'.$otherScope->learnerId));
        $this->assertTrue(DB::table('users')->where('id', $other->id)->exists());
        $this->assertTrue(LearnerTables::query($otherScope, 'learner_keys')->exists());
        $this->assertGreaterThan(0, LearnerTables::query($otherScope, 'notes')->count());
        $this->assertFalse(DB::table('jobs')->exists());
        $this->assertFalse(DB::table('failed_jobs')->exists());
        $this->assertFalse(DB::table('oauth_access_tokens')->exists());
        $this->assertFalse(DB::table('oauth_refresh_tokens')->exists());
        $this->assertFalse(DB::table('oauth_auth_codes')->exists());
        $this->assertTrue(DB::table('oauth_clients')->where('id', $client)->exists());
        $this->assertFalse(DB::table('password_reset_tokens')->where('email', $user->email)->exists());
        $log = DB::table('erasure_log')->first();
        $this->assertSame($scope->learnerId, $log->learner_ref);
        $this->assertStringNotContainsString('Synthetic private', json_encode($log));
        $this->assertTrue(app(Erasure::class)->erase(Principal::system(), $request->id));
        $this->assertSame(1, DB::table('erasure_log')->count());
    }

    public function test_interruption_at_every_stage_retries_without_premature_completion(): void
    {
        foreach (['access', 'content', 'files', 'key', 'sweep', 'complete'] as $stop) {
            [$user, $by, $scope] = $this->fixture();
            $request = $this->request($user, $by);
            $this->travel(3)->hours();
            Event::listen(ErasureStage::class, function ($event) use ($stop) {
                if ($event->stage === $stop) {
                    throw new RuntimeException('Synthetic private failure must not be logged.');
                }
            });
            $this->assertThrows(fn () => app(Erasure::class)->erase(Principal::system(), $request->id), RuntimeException::class);
            $this->assertFalse(DB::table('erasure_log')->where('request_id', $request->id)->exists());
            $this->assertSame(! in_array($stop, ['sweep', 'complete'], true), LearnerTables::query($scope, 'learner_keys')->exists());
            $this->assertSame('erasure_retry_required', DB::table('erasure_requests')->where('id', $request->id)->value('error_code'));
            Event::forget(ErasureStage::class);
            $this->assertTrue(app(Erasure::class)->erase(Principal::system(), $request->id));
        }
    }

    public function test_post_key_sweep_removes_late_metadata_and_old_jobs_exit_without_touching_files(): void
    {
        [$user, $by, $scope, , , $file] = $this->fixture();
        $path = 'learners/'.$scope->learnerId.'/files/'.$file->id;
        $request = $this->request($user, $by);
        $raw = Files::disk()->get($path);
        (new ExtractFileText($file->id, $path, $scope->learnerId))->handle();
        (new MakeFilePreview($file->id, $path, 'docx', $scope->learnerId))->handle();
        $this->assertSame($raw, Files::disk()->get($path));
        $this->travel(3)->hours();
        Event::listen(ErasureStage::class, function ($event) use ($scope) {
            if ($event->stage === 'sweep') {
                LearnerTables::insert($scope, 'content_tombstones', ['entity_type' => 'note', 'entity_id' => Ids::new(), 'kind' => 'deleted', 'at' => now()]);
                Cache::put('engine-trouble:'.$scope->learnerId, 'Late metadata');
            }
        });
        $this->assertTrue(app(Erasure::class)->erase(Principal::system(), $request->id));
        $this->assertFalse(LearnerTables::query($scope, 'content_tombstones')->exists());
        $this->assertNull(Cache::get('engine-trouble:'.$scope->learnerId));
    }

    public function test_audit_events_survive_with_identifying_fields_removed_and_runtime_cannot_edit_them(): void
    {
        [$user, $by, $scope] = $this->fixture();
        DB::table('audit_log')->insert(['occurred_at' => now(), 'actor_type' => 'user', 'actor_user_id' => $user->id, 'actor_role' => 'student', 'action' => 'synthetic.event', 'target_type' => 'learner', 'target_id' => $scope->learnerId, 'metadata' => json_encode(['account' => $user->id]), 'ip' => '192.0.2.1', 'user_agent' => 'Synthetic agent']);
        $request = $this->request($user, $by);
        $before = DB::table('audit_log')->count();
        $this->travel(3)->hours();
        app(Erasure::class)->erase(Principal::system(), $request->id);
        $this->assertSame($before, DB::table('audit_log')->count());
        $event = DB::table('audit_log')->where('action', 'synthetic.event')->first();
        foreach (['actor_user_id', 'target_id', 'ip', 'user_agent'] as $field) {
            $this->assertNull($event->$field);
        }
        $this->assertStringNotContainsString($user->id, (string) $event->metadata);
        $this->assertThrows(fn () => DB::table('audit_log')->where('id', $event->id)->update(['ip' => null]), QueryException::class);
        $this->assertThrows(fn () => DB::table('erasure_requests')->where('id', $request->id)->update(['stage' => 'complete']), QueryException::class);
        $this->assertThrows(fn () => DB::table('erasure_log')->insert(['request_id' => Ids::new(), 'user_id' => Ids::new(), 'requested_at' => now(), 'erased_at' => now(), 'version' => 1]), QueryException::class);
    }

    public function test_expired_active_temp_prevents_key_destruction_until_existing_cleanup_can_finish(): void
    {
        [$user, $by, $scope] = $this->fixture();
        $request = $this->request($user, $by);
        $temp = new TempWorkspace;
        touch($temp->path.'/.lease', time() - 14400);
        $this->travel(3)->hours();
        $this->assertThrows(fn () => app(Erasure::class)->erase(Principal::system(), $request->id), RuntimeException::class);
        $this->assertTrue(LearnerTables::query($scope, 'learner_keys')->exists());
        $temp->close();
        $this->assertTrue(app(Erasure::class)->erase(Principal::system(), $request->id));
    }

    public function test_erasure_refuses_a_user_principal_and_recovery_requeues_due_requests(): void
    {
        [$user, $by] = $this->fixture();
        $request = $this->request($user, $by);
        $this->assertThrows(fn () => app(Erasure::class)->erase($by, $request->id), Forbidden::class);
        $this->travel(3)->hours();
        // Simulate a dispatch lost after persisting the request. Normal unique
        // delivery still suppresses duplicate pending jobs.
        Cache::lock('laravel_unique_job:'.EraseLearner::class.':'.$request->id)->forceRelease();
        Queue::fake();
        $this->artisan('vistud:erasure:retry-pending')->assertSuccessful();
        Queue::assertPushed(EraseLearner::class, fn ($job) => $job->requestId === $request->id && $job->connection === 'database');
    }

    public function test_shared_object_callbacks_are_never_corrupted_and_delay_erasure_until_resolved(): void
    {
        [$user, $by, $scope] = $this->fixture();
        $batch = Ids::new();
        $options = serialize((object) ['learner' => $scope->learnerId]);
        DB::table('job_batches')->insert(['id' => $batch, 'name' => 'Synthetic callback batch', 'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => $options, 'created_at' => time()]);
        $request = $this->request($user, $by);
        $this->travel(3)->hours();
        $this->assertThrows(fn () => app(Erasure::class)->erase(Principal::system(), $request->id), RuntimeException::class);
        $this->assertSame($options, DB::table('job_batches')->where('id', $batch)->value('options'));
        $this->assertSame('shared_batch_cleanup_pending', DB::table('erasure_requests')->where('id', $request->id)->value('error_code'));
        $this->assertTrue(LearnerTables::query($scope, 'learner_keys')->exists());
        $this->assertFalse(DB::table('erasure_log')->where('request_id', $request->id)->exists());
        // The operator completes/rebuilds the shared callback without the erased
        // learner. No serialized object is instantiated by the erasure worker.
        DB::table('job_batches')->where('id', $batch)->update(['options' => serialize([])]);
        $this->assertTrue(app(Erasure::class)->erase(Principal::system(), $request->id));
    }
}
