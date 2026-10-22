<?php

namespace Tests\Feature\Platform;

use App\Identity\AccountFactory;
use App\Identity\Accounts;
use App\Identity\PrincipalFactory;
use App\Models\User;
use App\Platform\Access\LearnerKeys;
use App\Platform\Access\LearnerKeyStore;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Unavailable;
use App\Platform\Ids;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\Fixtures\LearnerKeyComponent;
use Tests\Fixtures\ProbeLearnerKey;
use Tests\TestCase;

class LearnerKeysTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private function scope(): LearnerScope
    {
        return LearnerScope::of($this->principal($this->student()));
    }

    private function environment(string $name, string $value): void
    {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    public function test_keys_load_lazily_once_per_learner_and_are_separate_by_purpose(): void
    {
        $a = $this->scope();
        $b = $this->scope();
        $reads = 0;
        DB::listen(function ($event) use (&$reads) {
            if ($event->connectionName === 'mysql_keys' && str_starts_with($event->sql, 'select')) {
                $reads++;
            }
        });
        $this->assertSame(0, $reads);
        $this->assertSame($a->key('content'), $a->key('content'));
        $this->assertNotSame($a->key('content'), $a->key('search'));
        $this->assertNotSame($a->key('search'), $a->key('cache'));
        $this->assertSame(1, $reads);
        $this->assertNotSame($a->key('content'), $b->key('content'));
        $this->assertSame(2, $reads);
        $ciphertext = $a->encrypter()->encryptString('synthetic private note');
        $this->assertSame('synthetic private note', $a->encrypter()->decryptString($ciphertext));
        $this->expectException(DecryptException::class);
        $b->encrypter()->decryptString($ciphertext);
    }

    public function test_provisioning_reads_back_an_existing_row_without_overwriting_its_key(): void
    {
        $scope = $this->scope();
        $before = LearnerTables::query($scope, 'learner_keys')->first();
        $key = $scope->key('content');
        $this->assertFalse(app(LearnerKeyStore::class)->provision($scope));
        $this->assertEquals($before, LearnerTables::query($scope, 'learner_keys')->first());
        app()->forgetScopedInstances();
        $this->assertSame($key, $scope->key('content'));
        $this->assertSame(1, LearnerTables::query($scope, 'learner_keys')->count());
    }

    public function test_missing_master_key_fails_even_with_a_readable_key_row(): void
    {
        $scope = $this->scope();
        $this->environment('VISTUD_MASTER_KEY', '');
        $this->expectException(Unavailable::class);
        $scope->key('content');
    }

    public function test_missing_key_never_gets_recreated_by_unlock_or_provisioning_command(): void
    {
        $user = $this->student();
        $principal = $this->principal($user);
        $scope = LearnerScope::of($principal);
        LearnerTables::query($scope, 'learner_keys')->delete();
        $this->assertThrows(fn () => LearnerKeys::unlock($principal), Unavailable::class);
        $this->artisan('vistud:keys:provision')->expectsOutputToContain('Missing previously provisioned key:')->assertFailed();
        $this->assertFalse(LearnerTables::query($scope, 'learner_keys')->exists());
    }

    public function test_wrapped_key_cannot_be_transplanted_to_another_learner(): void
    {
        $a = $this->scope();
        $b = $this->scope();
        $row = LearnerTables::query($a, 'learner_keys')->first();
        LearnerTables::query($b, 'learner_keys')->update(['wrapped_key' => $row->wrapped_key]);
        $this->expectException(Unavailable::class);
        $b->key('content');
    }

    public function test_wrong_key_version_is_refused(): void
    {
        $scope = $this->scope();
        LearnerTables::query($scope, 'learner_keys')->update(['key_version' => 2]);
        $this->expectException(Unavailable::class);
        $scope->key('content');
    }

    public function test_damaged_ciphertext_is_refused(): void
    {
        $scope = $this->scope();
        LearnerTables::query($scope, 'learner_keys')->update(['wrapped_key' => base64_encode(random_bytes(80))]);
        $this->expectException(Unavailable::class);
        $scope->key('content');
    }

    public function test_rotation_resumes_after_one_row_and_preserves_existing_content(): void
    {
        $a = $this->scope();
        $b = $this->scope();
        $before = [$a->key('content'), $b->key('content')];
        $ciphertext = $a->encrypter()->encryptString('before rotation');
        $old = $_ENV['VISTUD_MASTER_KEY'];
        $this->environment('VISTUD_PREVIOUS_MASTER_KEY', $old);
        $this->environment('VISTUD_MASTER_KEY', 'base64:'.base64_encode(random_bytes(32)));
        $this->assertTrue(app(LearnerKeyStore::class)->rotate($a));
        app()->forgetScopedInstances();
        // Mixed old/new wrapping rows remain readable before resuming the command.
        $this->assertSame($before, [$a->key('content'), $b->key('content')]);
        $this->artisan('vistud:keys:rotate')->expectsOutputToContain('Rewrapped keys: 1')->assertSuccessful();
        $this->artisan('vistud:keys:rotate')->expectsOutputToContain('Rewrapped keys: 0')->assertSuccessful();
        $this->environment('VISTUD_PREVIOUS_MASTER_KEY', '');
        app()->forgetScopedInstances();
        $this->assertSame($before, [$a->key('content'), $b->key('content')]);
        $this->assertSame('before rotation', $a->encrypter()->decryptString($ciphertext));
    }

    public function test_a_new_account_commits_with_its_key_and_marker(): void
    {
        $user = app(Accounts::class)->create(Principal::system(), 'Synthetic', 'new-key@example.test', Str::password(24));
        $principal = $this->principal($user);
        LearnerKeys::unlock($principal);
        $this->assertNotNull(DB::table('learners')->where('user_id', $user->id)->value('key_provisioned_at'));
        $this->assertSame(1, LearnerTables::query(LearnerScope::of($principal), 'learner_keys')->count());
    }

    public function test_failed_account_transaction_removes_its_precreated_key(): void
    {
        $before = LearnerTables::keyRowsForMaintenance(Principal::system())->count();
        $this->assertThrows(fn () => app(AccountFactory::class)->create(
            'Synthetic', 'rollback-key@example.test', Str::password(24), true, 'UTC', null,
            afterCreate: fn (User $user) => throw new RuntimeException('Synthetic account failure.'),
        ), RuntimeException::class);
        $this->assertFalse(User::query()->where('email', 'rollback-key@example.test')->exists());
        $this->assertSame($before, LearnerTables::keyRowsForMaintenance(Principal::system())->count());
    }

    public function test_missing_master_prevents_account_commit(): void
    {
        $this->environment('VISTUD_MASTER_KEY', '');
        $this->assertThrows(fn () => app(Accounts::class)->create(Principal::system(), 'Synthetic', 'no-master@example.test', Str::password(24)), Unavailable::class);
        $this->assertFalse(User::query()->where('email', 'no-master@example.test')->exists());
    }

    public function test_existing_learner_provisioning_has_a_dry_run_and_is_repeatable(): void
    {
        $scope = $this->scope();
        LearnerTables::query($scope, 'learner_keys')->delete();
        DB::table('learners')->where('id', $scope->learnerId)->update(['key_provisioned_at' => null]);
        $this->artisan('vistud:keys:provision --dry-run')->assertSuccessful();
        $this->assertFalse(LearnerTables::query($scope, 'learner_keys')->exists());
        $this->artisan('vistud:keys:provision')->assertSuccessful();
        $key = $scope->key('content');
        $this->artisan('vistud:keys:provision')->assertSuccessful();
        app()->forgetScopedInstances();
        $this->assertSame($key, $scope->key('content'));
    }

    public function test_provisioning_reports_orphans_and_prunes_only_old_ones(): void
    {
        $scope = LearnerScope::forJob(Ids::new());
        app(LearnerKeyStore::class)->provision($scope);
        LearnerTables::query($scope, 'learner_keys')->update(['created_at' => now()->subHours(2)]);
        $this->artisan('vistud:keys:provision --prune-orphans --dry-run')->expectsOutputToContain('Orphan: '.$scope->learnerId)->assertSuccessful();
        $this->assertTrue(LearnerTables::query($scope, 'learner_keys')->exists());
        $this->artisan('vistud:keys:provision --prune-orphans')->assertSuccessful();
        $this->assertFalse(LearnerTables::query($scope, 'learner_keys')->exists());
    }

    public function test_key_table_cannot_use_the_content_database(): void
    {
        config(['database.connections.mysql_keys.database' => config('database.connections.mysql.database')]);
        $this->expectException(RuntimeException::class);
        LearnerTables::query(LearnerScope::forJob(Ids::new()), 'learner_keys');
    }

    public function test_scoped_ring_is_flushed_between_lifetimes_and_never_serialized(): void
    {
        $scope = $this->scope();
        $scope->key('content');
        $old = app(LearnerKeys::class);
        app()->forgetScopedInstances();
        $this->assertNotSame($old, app(LearnerKeys::class));
        $this->assertSame(['loaded' => 0], app(LearnerKeys::class)->__debugInfo());
        $this->expectException(\LogicException::class);
        serialize($old);
    }

    public function test_sync_jobs_forget_keys_including_after_a_failure(): void
    {
        $a = $this->scope();
        $b = $this->scope();
        $a->key('content');
        ProbeLearnerKey::$observed = [];
        ProbeLearnerKey::dispatchSync($a->learnerId);
        $this->assertThrows(fn () => ProbeLearnerKey::dispatchSync($b->learnerId, true), RuntimeException::class);
        ProbeLearnerKey::dispatchSync($a->learnerId);
        $this->assertSame([
            [$a->learnerId, 0, 1, 'synthetic probe'], [$b->learnerId, 0, 1, 'synthetic probe'], [$a->learnerId, 0, 1, 'synthetic probe'],
        ], ProbeLearnerKey::$observed);
        $this->assertSame(['loaded' => 0], app(LearnerKeys::class)->__debugInfo());
    }

    public function test_one_database_worker_processes_a_b_a_including_a_failed_job(): void
    {
        $a = $this->scope();
        $b = $this->scope();
        ProbeLearnerKey::$observed = [];
        ProbeLearnerKey::dispatch($a->learnerId)->onConnection('database');
        ProbeLearnerKey::dispatch($b->learnerId, true)->onConnection('database');
        ProbeLearnerKey::dispatch($a->learnerId)->onConnection('database');
        $this->artisan('queue:work', [
            // The default 128 MB cap ends a worker (exit 12) inside a long test process.
            'connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--memory' => 4096,
        ])->assertSuccessful();
        $this->assertSame([
            [$a->learnerId, 0, 1, 'synthetic probe'], [$b->learnerId, 0, 1, 'synthetic probe'], [$a->learnerId, 0, 1, 'synthetic probe'],
        ], ProbeLearnerKey::$observed);
        $this->assertSame(['loaded' => 0], app(LearnerKeys::class)->__debugInfo());
    }

    public function test_web_and_livewire_actions_use_the_same_lazy_scope_path(): void
    {
        $user = $this->student();
        Route::middleware(['web', 'auth'])->get('/_test/learner-key', function (PrincipalFactory $principals) {
            $scope = LearnerScope::of($principals->fromRequest(request()));
            $ciphertext = $scope->encrypter()->encryptString('synthetic web');

            return response($scope->encrypter()->decryptString($ciphertext));
        });
        $this->actingAs($user)->get('/_test/learner-key')->assertOk()->assertSee('synthetic web');
        Livewire::actingAs($user)->test(LearnerKeyComponent::class)->call('probe')->assertSet('result', 'synthetic livewire');
    }

    public function test_after_response_work_also_forgets_keys_after_failure(): void
    {
        $a = $this->scope();
        $b = $this->scope();
        $a->key('content');
        ProbeLearnerKey::$observed = [];
        ProbeLearnerKey::dispatchAfterResponse($a->learnerId);
        ProbeLearnerKey::dispatchAfterResponse($b->learnerId, true);
        $this->assertThrows(fn () => app()->terminate(), RuntimeException::class);
        $this->assertSame([
            [$a->learnerId, 0, 1, 'synthetic probe'], [$b->learnerId, 0, 1, 'synthetic probe'],
        ], ProbeLearnerKey::$observed);
        $this->assertSame(['loaded' => 0], app(LearnerKeys::class)->__debugInfo());
    }
}
