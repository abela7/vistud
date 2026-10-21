<?php

namespace Tests\Acceptance\G3;

use App\Engine\EngineFailed;
use App\Engine\Troubles;
use App\Identity\Accounts;
use App\Identity\Erasure;
use App\Identity\ErasureLedger;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Study\ClassRecordings;
use App\Study\Files;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Acceptance\G2\World;
use Tests\Concerns\ConnectsAiApps;
use Tests\TestCase;

/**
 * G3: the erasure drill (ADR 0001, "G3: Erasure drill"). B is given everything ViStud keeps: content in every learner
 * table, files and derived files, a class recording, an AI-app connection with its tokens, cache entries and journal
 * events. B is erased; then every table, file, log line and cache entry is searched for B's marker, email and ids. A
 * restored backup is re-erased from the independent ledger, and is unreadable without B's destroyed key. A device that
 * comes back is told the account was deleted before it may replay a draft.
 *
 * The measured results are in ADR 0001, "What remains after erasure". Written by the independent validator (S6).
 */
class ErasureDrillTest extends TestCase
{
    use ConnectsAiApps, World;

    /** Tables that keep the erased account's ids by design (ADR 0004): the request and the content-free log. */
    private const KEEP_IDS = ['erasure_requests', 'erasure_log'];

    /** Erasure runs on the owner connection: nothing may hide inside a test transaction. */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        // Production keeps caches in the database: so does the drill, so the table search covers them.
        config(['cache.default' => 'database']);
        $this->buildWorld();
        $this->giveBEverythingElse();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function giveBEverythingElse(): void
    {
        $by = $this->by('B');
        $ids = $this->ids('B');
        $recordings = app(ClassRecordings::class);
        $recordings->allow($by, $ids['class']);
        $take = $recordings->start($by, $ids['class']);
        $recordings->accept($by, $ids['class'], $take->id, 1, $this->temp('webm '.$this->marker('B')), 'audio/webm');
        $recordings->finish($by, $ids['class'], $take->id, 60);
        $this->connectApp($this->learners['B']['user'], 'full');
        app(Troubles::class)->note($by, 'tutor', 'fake/tutor', new EngineFailed('engine_failed', 'trouble'));
    }

    private function erase(string $who): object
    {
        app(Accounts::class)->requestDeletion($this->by($who), $this->ids($who)['user']);
        $request = DB::table('erasure_requests')->where('user_id', $this->ids($who)['user'])->first();
        $this->travel(3)->hours();
        $this->travel(1)->minutes();
        app(Erasure::class)->erase(Principal::system(), $request->id);
        $this->assertNotNull(DB::table('erasure_requests')->where('id', $request->id)->value('completed_at'), 'the erasure completed');

        return $request;
    }

    /** @return list<string> "table.column" for every stored value that still carries one of $needles */
    private function searchEveryTable(string $connection, array $needles, array $skip = []): array
    {
        $database = DB::connection($connection)->getDatabaseName();
        $columns = DB::connection($connection)->select("SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = ? AND data_type IN ('char','varchar','tinytext','text','mediumtext','longtext','json','binary','varbinary','blob','mediumblob','longblob')", [$database]);
        $hits = [];
        foreach ($columns as $column) {
            if (in_array($column->t, $skip, true) || $column->t === 'migrations') {
                continue;
            }
            foreach ($needles as $label => $needle) {
                if (DB::connection($connection)->table($column->t)->whereRaw('LOWER(CAST(`'.$column->c.'` AS CHAR CHARACTER SET utf8mb4)) LIKE ?', ['%'.strtolower($needle).'%'])->exists()) {
                    $hits[] = "{$column->t}.{$column->c} ({$label})";
                }
            }
        }

        return $hits;
    }

    public function test_after_erasure_no_table_file_log_or_cache_holds_anything_of_b_and_a_and_c_are_untouched(): void
    {
        $b = $this->ids('B');
        $needles = ['marker' => $this->marker('B'), 'email' => $this->learners['B']['user']->email, 'user id' => $b['user'], 'learner id' => $b['learner']];
        $files = fn () => collect(['local', 'derived', 'temporary'])->flatMap(fn ($disk) => array_map(fn ($p) => "{$disk}:{$p}", Storage::disk($disk)->allFiles()))->all();
        $this->assertNotEmpty(array_filter($files(), fn ($p) => str_contains($p, $b['learner'])), 'B had files before the drill');
        $this->assertNotEmpty($this->searchEveryTable('mysql', ['learner id' => $b['learner']]), 'the search finds B before the drill');

        $this->erase('B');

        $this->assertSame([], $this->searchEveryTable('mysql', $needles, self::KEEP_IDS), 'Something of B survived in the database.');
        $this->assertSame([], $this->searchEveryTable('mysql_keys', $needles), 'Something of B survived in the key database.');
        $kept = $this->searchEveryTable('mysql', ['marker' => $this->marker('B'), 'email' => $this->learners['B']['user']->email]);
        $this->assertSame([], $kept, 'The erasure records may keep ids, never content or the email.');

        foreach (['local', 'derived', 'temporary'] as $disk) {
            foreach (Storage::disk($disk)->allFiles() as $path) {
                $this->assertStringNotContainsString($b['learner'], $path, "{$disk}:{$path} is B's");
                $this->assertStringNotContainsStringIgnoringCase($this->marker('B'), (string) Storage::disk($disk)->get($path), "{$disk}:{$path} holds B's text");
            }
        }
        $temps = config('vistud.files.temp_root');
        if (is_dir($temps)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($temps, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $this->assertStringNotContainsStringIgnoringCase($this->marker('B'), (string) file_get_contents($file->getPathname()), "temp {$file->getPathname()} holds B's text");
            }
        }
        $lines = implode("\n", $this->logLines);
        foreach ($needles as $label => $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $lines, "a log line carries B's {$label}");
        }

        // A and C keep everything, readable.
        foreach (['A', 'C'] as $who) {
            $this->assertStringContainsString($this->marker($who), json_encode(app(Workspaces::class)->list($this->by($who))), "{$who}'s courses");
            $this->assertStringContainsString($this->marker($who), (string) app(Notes::class)->open($this->by($who), $this->ids($who)['note'])->title, "{$who}'s note");
            $this->assertTrue(LearnerTables::query(LearnerScope::forJob($this->ids($who)['learner']), 'learner_keys')->exists(), "{$who}'s key");
        }
        // C uploaded the very same bytes as B: C's copy is C's own and survives.
        $this->assertStringContainsString($this->marker('C'), (string) app(Files::class)->find($this->by('C'), $this->ids('C')['file'])->fileName());
    }

    public function test_a_restored_backup_is_unreadable_without_bs_key_and_is_re_erased_from_the_independent_ledger(): void
    {
        $owner = DB::connection(config('vistud.database.owner_connection'));
        $b = $this->ids('B');
        $scope = LearnerScope::forJob($b['learner']);
        // The backup: every row of B's, and B's identity rows, as a data backup taken before the erasure holds them.
        $backup = [];
        foreach (LearnerTables::TABLES as $table) {
            $backup[$table] = $owner->table($table)->where('learner_id', $b['learner'])->get()->map(fn ($r) => (array) $r)->all();
        }
        foreach (['users' => 'id', 'learners' => 'user_id', 'user_roles' => 'user_id'] as $table => $column) {
            $backup[$table] = $owner->table($table)->where($column, $b['user'])->get()->map(fn ($r) => (array) $r)->all();
        }

        $this->erase('B');
        $export = config('vistud.erasure.ledger_path').'/latest.jsonl';
        $this->artisan('vistud:erasure:ledger', ['action' => 'export', '--output' => $export])->assertSuccessful();
        $head = app(ErasureLedger::class)->read()['hash'];

        // The data backup is restored; the key backups (kept 7 days) have expired, so B's key is not.
        $owner->table('erasure_requests')->where('user_id', $b['user'])->delete();
        $owner->table('erasure_log')->where('user_id', $b['user'])->delete();
        foreach (['users', 'learners', 'user_roles', ...LearnerTables::TABLES] as $table) {
            if (($backup[$table] ?? []) !== []) {
                // A dump restores stored columns; MySQL recomputes the generated ones.
                $generated = array_flip(array_column($owner->select("SELECT column_name AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND extra LIKE '%GENERATED%'", [$owner->getDatabaseName(), $table]), 'c'));
                $owner->table($table)->insert(array_map(fn (array $row) => array_diff_key($row, $generated), $backup[$table]));
            }
        }
        // Restored ciphertext: B's text cannot be read by anyone, and the raw rows carry none of it.
        $this->assertSame([], $this->searchEveryTable('mysql', ['marker' => $this->marker('B')]), 'a restored row is readable without a key');
        $this->assertThrows(fn () => LearnerTables::query($scope, 'notes')->get(), \Throwable::class);

        // The independent ledger erases the restored rows again.
        app()->maintenanceMode()->activate([]);
        $this->artisan('vistud:erasure:replay', ['ledger' => $export, '--head' => $head, '--offline-confirmed' => true])->assertSuccessful();
        app()->maintenanceMode()->deactivate();
        $this->assertSame([], $this->searchEveryTable('mysql', ['user id' => $b['user'], 'learner id' => $b['learner']], self::KEEP_IDS), 'the replay left something of B');
        $this->assertTrue($owner->table('users')->where('id', $this->ids('A')['user'])->exists(), 'A survives the replay');
    }

    public function test_a_device_coming_back_during_the_grace_period_is_told_the_account_was_deleted_before_any_draft_is_saved(): void
    {
        $b = $this->ids('B');
        app(Accounts::class)->requestDeletion($this->by('B'), $b['user']);
        $before = (string) DB::table('note_versions')->where('note_id', $b['note'])->max('version');

        $this->app['auth']->forgetGuards();
        $response = $this->withSession([Auth::guard('web')->getName() => $b['user']])
            ->putJson("/api/v1/notes/{$b['note']}", ['base_version' => 1, 'doc' => ['type' => 'doc', 'content' => []], 'title' => 'draft']);
        $response->assertStatus(401)->assertJsonPath('error.code', 'account_deleted');
        $this->assertSame($before, (string) DB::table('note_versions')->where('note_id', $b['note'])->max('version'), 'a draft was saved for a deleted account');
    }

    public function test_a_device_coming_back_after_the_erasure_is_still_told_the_account_was_deleted(): void
    {
        $b = $this->ids('B');
        $this->erase('B');

        $this->app['auth']->forgetGuards();
        $response = $this->withSession([Auth::guard('web')->getName() => $b['user']])
            ->putJson("/api/v1/notes/{$b['note']}", ['base_version' => 1, 'doc' => ['type' => 'doc', 'content' => []], 'title' => 'draft']);
        // ADR 0001 G3 step 6: the device must learn of the deletion and purge its drafts. A plain 401 makes the editor
        // keep them for a later sign-in (resources/js/note/autosave.js), and they wait 30 days for local expiry.
        $response->assertStatus(401)->assertJsonPath('error.code', 'account_deleted');
    }
}
