<?php

namespace Tests\Feature\Identity;

use App\Identity\Accounts;
use App\Identity\Erasure;
use App\Identity\ErasureLedger;
use App\Identity\ErasureStage;
use App\Identity\RestoreErasures;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Forbidden;
use App\Platform\Ids;
use App\Study\Files;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Synthetic restored-row regressions. Independent dump/restore G3 is S6. */
class ErasureRestoreTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        app()->maintenanceMode()->activate([]);
        $this->beforeApplicationDestroyed(fn () => app()->maintenanceMode()->deactivate());
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
        $course = app(Workspaces::class)->create($by, ['name' => 'Synthetic restored course']);
        app(Notes::class)->create($by, 'workspace', $course->id, 'Synthetic private restored note');
        $file = app(Files::class)->upload($by, 'workspace', $course->id, $this->temp('Synthetic restored private bytes'), 'Reading.txt');

        return [$user, $by, $scope, $file];
    }

    private function request($user, $by): object
    {
        app(Accounts::class)->requestDeletion($by, $user->id);

        return DB::table('erasure_requests')->where('user_id', $user->id)->first();
    }

    public function test_main_file_and_key_only_restores_are_re_erased_from_the_independent_export(): void
    {
        $owner = DB::connection(config('vistud.database.owner_connection'));
        $this->assertSame('vistud_test', $owner->getDatabaseName());
        $this->assertSame('vistud_keys_test', DB::connection('mysql_keys')->getDatabaseName());
        [$user, $by, $scope, $file] = $this->fixture();
        [$other, , $otherScope] = $this->fixture();
        $snapshot = [];
        foreach (LearnerTables::TABLES as $table) {
            $snapshot[$table] = $owner->table($table)->where('learner_id', $scope->learnerId)->get()->map(fn ($row) => (array) $row)->all();
        }
        $identity = [];
        foreach (['users' => 'id', 'learners' => 'user_id', 'user_roles' => 'user_id'] as $table => $column) {
            $identity[$table] = $owner->table($table)->where($column, $user->id)->get()->map(fn ($row) => (array) $row)->all();
        }
        $key = (array) LearnerTables::query($scope, 'learner_keys')->first();
        $storageKey = (string) LearnerTables::query($scope, 'files')->where('id', $file->id)->value('storage_key');
        $bytes = Files::disk()->get($storageKey);
        $request = $this->request($user, $by);
        $this->travel(3)->hours();
        app(Erasure::class)->erase(Principal::system(), $request->id);
        $ledger = app(ErasureLedger::class);
        $export = config('vistud.erasure.ledger_path').'/latest.jsonl';
        $this->artisan('vistud:erasure:ledger', ['action' => 'export', '--output' => $export, '--dry-run' => true])->assertSuccessful();
        $this->assertFileDoesNotExist($export);
        $this->artisan('vistud:erasure:ledger', ['action' => 'export', '--output' => $export])->assertSuccessful();
        $state = $ledger->read();
        // Recreate synthetic pre-erasure rows/bytes, deliberately losing local erasure history.
        $owner->table('erasure_requests')->where('user_id', $user->id)->delete();
        $owner->table('erasure_log')->where('user_id', $user->id)->delete();
        foreach ($identity as $table => $rows) {
            if ($rows !== []) {
                $owner->table($table)->insert($rows);
            }
        }
        foreach ($snapshot as $table => $rows) {
            if ($rows !== []) {
                $owner->table($table)->insert($rows);
            }
        }
        DB::connection('mysql_keys')->table('learner_keys')->insert($key);
        Files::disk()->put($storageKey, $bytes);
        $args = ['ledger' => $export, '--head' => $state['hash']];
        $this->artisan('vistud:erasure:replay', $args + ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('active', $owner->table('users')->where('id', $user->id)->value('status'));
        $this->assertSame($bytes, Files::disk()->get($storageKey));
        app()->maintenanceMode()->deactivate();
        $this->artisan('vistud:erasure:replay', $args + ['--offline-confirmed' => true])->assertFailed();
        $this->assertSame('active', $owner->table('users')->where('id', $user->id)->value('status'));
        app()->maintenanceMode()->activate([]);
        $this->artisan('vistud:erasure:replay', $args)->assertFailed();
        $this->artisan('vistud:erasure:replay', $args + ['--offline-confirmed' => true])->assertSuccessful();
        $this->assertFalse($owner->table('users')->where('id', $user->id)->exists());
        foreach (LearnerTables::TABLES as $table) {
            $this->assertFalse($owner->table($table)->where('learner_id', $scope->learnerId)->exists(), $table);
        }
        $this->assertFalse(LearnerTables::query($scope, 'learner_keys')->exists());
        $this->assertFalse(Files::disk()->exists($storageKey));
        // A key-only restore must not be skipped because the main DB says complete.
        DB::connection('mysql_keys')->table('learner_keys')->insert($key);
        Files::disk()->put($storageKey, $bytes);
        $this->artisan('vistud:erasure:replay', $args + ['--offline-confirmed' => true])->assertSuccessful();
        $this->assertFalse(LearnerTables::query($scope, 'learner_keys')->exists());
        $this->assertFalse(Files::disk()->exists($storageKey));
        $this->assertTrue($owner->table('users')->where('id', $other->id)->exists());
        $this->assertTrue(LearnerTables::query($otherScope, 'learner_keys')->exists());
        $this->assertSame($state, $ledger->read());
    }

    public function test_stale_or_corrupt_exports_make_no_changes_and_pending_intent_keeps_recovery_closed(): void
    {
        [$user, $by, $scope] = $this->fixture();
        $ledger = app(ErasureLedger::class);
        $root = config('vistud.erasure.ledger_path');
        $old = $ledger->export($root.'/old.jsonl');
        $request = $this->request($user, $by);
        $owner = DB::connection(config('vistud.database.owner_connection'));
        $owner->table('users')->where('id', $user->id)->update(['status' => 'active']);
        $this->artisan('vistud:erasure:replay', ['ledger' => $root.'/old.jsonl', '--head' => $old['hash'], '--offline-confirmed' => true])->assertFailed();
        $this->assertSame('active', $owner->table('users')->where('id', $user->id)->value('status'));
        $state = $ledger->export($root.'/new.jsonl');
        $args = ['ledger' => $root.'/new.jsonl', '--head' => $state['hash'], '--offline-confirmed' => true];
        $this->artisan('vistud:erasure:replay', $args)->assertFailed(); // Three-hour grace is not shortened.
        $this->assertSame('deleted', $owner->table('users')->where('id', $user->id)->value('status'));
        $this->assertTrue(LearnerTables::query($scope, 'learner_keys')->exists());
        $this->assertThrows(fn () => app(RestoreErasures::class)->replay($by, $root.'/new.jsonl', $state['hash'], false, true), Forbidden::class);
        $this->travel(3)->hours();
        Event::listen(ErasureStage::class, function ($event) {
            if ($event->stage === 'key') {
                throw new RuntimeException('Synthetic interrupted replay.');
            }
        });
        $this->artisan('vistud:erasure:replay', $args)->assertFailed();
        $this->assertTrue(LearnerTables::query($scope, 'learner_keys')->exists());
        Event::forget(ErasureStage::class);
        $this->artisan('vistud:erasure:replay', $args)->assertSuccessful();
        $this->artisan('vistud:erasure:replay', $args)->assertSuccessful(); // Completion-only advances allow resume.
        $this->assertFalse(LearnerTables::query($scope, 'learner_keys')->exists());
    }

    public function test_publication_failure_retry_and_missing_ledger_never_acknowledge_erasure_early(): void
    {
        [$user, $by, $scope] = $this->fixture();
        $ledger = app(ErasureLedger::class);
        $mock = \Mockery::mock(ErasureLedger::class)->makePartial();
        $mock->shouldReceive('completed')->andThrow(new RuntimeException('Synthetic independent export failure.'));
        $request = $this->request($user, $by);
        $this->travel(3)->hours();
        app()->instance(ErasureLedger::class, $mock);
        $this->assertThrows(fn () => app(Erasure::class)->erase(Principal::system(), $request->id), RuntimeException::class);
        $this->assertFalse(LearnerTables::query($scope, 'learner_keys')->exists());
        $this->assertFalse(DB::table('erasure_log')->where('request_id', $request->id)->exists());
        app()->instance(ErasureLedger::class, $ledger);
        $export = config('vistud.erasure.ledger_path').'/pending.jsonl';
        $state = $ledger->export($export);
        $this->artisan('vistud:erasure:replay', ['ledger' => $export, '--head' => $state['hash'], '--offline-confirmed' => true])->assertSuccessful();
        [$other, $otherBy, $otherScope] = $this->fixture();
        config(['vistud.erasure.ledger_path' => '']);
        $this->assertThrows(fn () => app(Accounts::class)->requestDeletion($otherBy, $other->id), RuntimeException::class);
        $this->assertSame('active', $other->fresh()->status);
        $this->assertTrue(LearnerTables::query($otherScope, 'learner_keys')->exists());
    }

    public function test_lost_database_commit_is_recovered_and_identity_conflicts_change_no_account(): void
    {
        [$user, $by, $scope] = $this->fixture();
        DB::beginTransaction();
        try {
            app(Accounts::class)->requestDeletion($by, $user->id);
        } finally {
            DB::rollBack(); // Synthetic transaction loss after durable intent.
        }
        $ledger = app(ErasureLedger::class);
        $record = array_values($ledger->read()['records'])[0]['intent'];
        $this->assertSame('active', $user->fresh()->status);
        $this->assertFalse(DB::table('erasure_requests')->where('user_id', $user->id)->exists());
        app(RestoreErasures::class)->recoverPending(Principal::system());
        $this->assertSame('deleted', $user->fresh()->status);
        $this->assertSame($record['request_id'], DB::table('erasure_requests')->where('user_id', $user->id)->value('id'));
        $this->assertSame($record['not_before'], DB::table('erasure_requests')->where('user_id', $user->id)->value('not_before'));
        $this->travel(3)->hours();
        app(Erasure::class)->erase(Principal::system(), $record['request_id']);
        $this->assertFalse(LearnerTables::query($scope, 'learner_keys')->exists());
        [$a, , $aScope] = $this->fixture();
        [$b, , $bScope] = $this->fixture();
        $ledger->intent((object) ['id' => Ids::new(), 'user_id' => $a->id, 'learner_ref' => $bScope->learnerId, 'requested_at' => now()->toDateTimeString(), 'not_before' => now()->addHours(3)->toDateTimeString()]);
        $path = config('vistud.erasure.ledger_path').'/conflict.jsonl';
        $state = $ledger->export($path);
        $this->artisan('vistud:erasure:replay', ['ledger' => $path, '--head' => $state['hash'], '--offline-confirmed' => true])->assertFailed();
        $this->assertSame('active', $a->fresh()->status);
        $this->assertSame('active', $b->fresh()->status);
        $this->assertTrue(LearnerTables::query($aScope, 'learner_keys')->exists());
        $this->assertTrue(LearnerTables::query($bScope, 'learner_keys')->exists());
    }
}
