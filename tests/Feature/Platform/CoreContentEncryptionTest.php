<?php

namespace Tests\Feature\Platform;

use App\Identity\AccountFactory;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\ContentCipher;
use App\Platform\Database\ContentColumns;
use App\Platform\Database\LearnerCache;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Unavailable;
use App\Platform\Ids;
use App\Study\Folders;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

class CoreContentEncryptionTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_every_manifest_column_is_ciphertext_and_partial_reads_return_its_original_value(): void
    {
        $by = $this->principal($this->student());
        $scope = LearnerScope::of($by);
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Synthetic course']);
        $id = Ids::new();
        LearnerTables::insert($scope, 'mcp_connections', ['id' => $id, 'oauth_client_id' => Ids::new(), 'kind' => 'manual', 'provider' => 'test', 'level' => 'read', 'consented_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $marker = 'private-'.bin2hex(random_bytes(16)).' 中文 %_';
        foreach (ContentColumns::S2A as $table => $fields) {
            $row = [];
            foreach (Schema::getColumns($table) as $column) {
                if ($column['auto_increment'] || $column['nullable'] || $column['default'] !== null) {
                    continue;
                }
                $name = $column['name'];
                $row[$name] = match (true) {
                    $name === 'learner_id' => $scope->learnerId,
                    $name === 'workspace_id' => $workspace->id,
                    $name === 'id', str_ends_with($name, '_id') => $id,
                    $column['type_name'] === 'date' => '2026-10-20',
                    in_array($column['type_name'], ['datetime', 'timestamp']) => '2026-10-20 12:00:00',
                    str_contains($column['type_name'], 'int') => 1,
                    $column['type_name'] === 'json' => '[]',
                    default => 'test',
                };
            }
            foreach ($fields as $field => $original) {
                $row[$field] = str_starts_with($original, 'json') ? json_encode(['text' => $marker], JSON_UNESCAPED_UNICODE) : $marker;
            }
            if ($table === 'journal_entries') {
                $row['position'] = 100;
            }
            LearnerTables::insert($scope, $table, $row);
            $query = DB::table($table)->where('learner_id', $scope->learnerId);
            foreach (ContentColumns::primary($table) as $key) {
                if (isset($row[$key])) {
                    $query->where($key, $row[$key]);
                }
            }
            $stored = $query->orderByDesc(ContentColumns::primary($table)[0])->first();
            $read = LearnerTables::query($scope, $table);
            foreach (ContentColumns::primary($table) as $key) {
                $read->where($key, $stored->$key);
            }
            foreach ($fields as $field => $original) {
                $this->assertStringNotContainsString($marker, $stored->$field, $table.'.'.$field);
                $this->assertSame($row[$field], (clone $read)->value($field), $table.'.'.$field);
                $this->assertSame([$row[$field]], (clone $read)->pluck($field)->all());
            }
            $this->assertSame(1, (int) $stored->content_version);
        }
    }

    public function test_ciphertext_authenticates_learner_row_column_table_and_version(): void
    {
        $scope = LearnerScope::of($this->principal($this->student()));
        $other = LearnerScope::of($this->principal($this->student()));
        $cipher = app(ContentCipher::class);
        $row = ['id' => Ids::new()];
        $encrypted = $cipher->encrypt($scope, 'note_versions', $row, 'title', 'secret');
        $this->assertSame('secret', $cipher->decrypt($scope, 'note_versions', $row, 'title', $encrypted));
        foreach ([
            fn () => $cipher->decrypt($other, 'note_versions', $row, 'title', $encrypted),
            fn () => $cipher->decrypt($scope, 'notes', $row, 'title', $encrypted),
            fn () => $cipher->decrypt($scope, 'note_versions', ['id' => Ids::new()], 'title', $encrypted),
            fn () => $cipher->decrypt($scope, 'note_versions', $row, 'doc', $encrypted),
            fn () => $cipher->decrypt($scope, 'note_versions', $row, 'title', substr($encrypted, 0, -8)),
            fn () => $cipher->decodeRow($scope, 'notes', ['id' => $row['id'], 'content_version' => 2, 'title' => $encrypted]),
        ] as $attempt) {
            $this->assertThrows($attempt, RuntimeException::class);
        }
        $this->assertSame('', $cipher->decrypt($scope, 'notes', $row, 'title', $cipher->encrypt($scope, 'notes', $row, 'title', '')));
        $this->assertNull($cipher->decrypt($scope, 'notes', $row, 'title', null));
        $this->assertThrows(fn () => $cipher->decodeRow($scope, 'engine_threads', ['id' => $row['id'], 'learner_id' => $other->learnerId, 'content_version' => 1, 'title' => null, 'summary' => null]), RuntimeException::class);
    }

    public function test_new_versions_copies_folder_moves_and_trash_restores_reencrypt_without_losing_text(): void
    {
        $by = $this->principal($this->student());
        $scope = LearnerScope::of($by);
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Course']);
        $notes = app(Notes::class);
        $note = $notes->create($by, 'workspace', $workspace->id, 'Same title');
        $notes->append($by, $note->id, [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Kept through copies.']]]]);
        $versions = DB::table('note_versions')->where('note_id', $note->id)->orderBy('version')->get();
        $this->assertNotSame($versions[0]->title, $versions[1]->title);
        $this->assertSame('Same title', $notes->open($by, $note->id)->title);
        $copy = (array) LearnerTables::query($scope, 'note_versions')->where('id', $versions[1]->id)->first();
        $copy['id'] = Ids::new();
        $copy['version'] = 3;
        LearnerTables::insert($scope, 'note_versions', $copy);
        $copied = DB::table('note_versions')->where('id', $copy['id'])->first();
        $this->assertNotSame($versions[1]->doc, $copied->doc);
        $this->assertSame($copy['doc'], LearnerTables::query($scope, 'note_versions')->where('id', $copy['id'])->value('doc'));
        $rawTitle = fn () => DB::table('notes')->where('id', $note->id)->value('title');
        $before = $rawTitle();
        $folder = app(Folders::class)->create($by, 'workspace', $workspace->id, 'Folder');
        $notes->move($by, $note->id, 'folder', $folder->id);
        $this->assertNotSame($before, $rawTitle());
        $notes->trash($by, $note->id);
        $beforeRestore = $rawTitle();
        $notes->restore($by, $note->id);
        $this->assertNotSame($beforeRestore, $rawTitle());
        $this->assertSame('Kept through copies.', NoteDoc::text($notes->open($by, $note->id)->doc));
    }

    public function test_a_note_near_its_real_two_megabyte_limit_fits_and_round_trips(): void
    {
        $by = $this->principal($this->student());
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Large note']);
        $note = app(Notes::class)->create($by, 'workspace', $workspace->id, str_repeat('文', 200));
        $text = str_repeat('x', NoteDoc::MAX_BYTES - 1000);
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
        app(Notes::class)->replaceDoc($by, $note->id, $doc);
        $raw = DB::table('note_versions')->where('note_id', $note->id)->orderByDesc('version')->first();
        $this->assertGreaterThan(3_000_000, strlen($raw->doc));
        $this->assertSame($text, NoteDoc::text(app(Notes::class)->open($by, $note->id)->doc));
        $this->assertSame('longtext', collect(Schema::getColumns('note_versions'))->firstWhere('name', 'doc')['type_name']);
    }

    public function test_content_sql_operations_are_refused_and_metadata_aggregates_still_work(): void
    {
        $scope = LearnerScope::of($this->principal($this->student()));
        foreach ([
            'like' => fn () => LearnerTables::query($scope, 'notes')->where('title', 'like', '%x%')->exists(),
            'sort' => fn () => LearnerTables::query($scope, 'notes')->orderBy('title')->get(),
            'json' => fn () => LearnerTables::query($scope, 'journal_entries')->selectRaw("JSON_EXTRACT(body, '$.judge.via') AS via")->get(),
            'aggregate' => fn () => LearnerTables::query($scope, 'notes')->max('title'),
            'delete' => fn () => LearnerTables::query($scope, 'notes')->where('title', 'x')->delete(),
            'alias' => fn () => LearnerTables::query($scope, 'notes')->get(['title AS label']),
            'json update' => fn () => LearnerTables::query($scope, 'engine_messages')->update(['effects->asked' => 'x']),
            'fulltext' => fn () => LearnerTables::query($scope, 'notes')->whereFullText(['title'], 'x')->exists(),
            'raw group' => fn () => LearnerTables::query($scope, 'notes')->groupByRaw('title')->count(),
        ] as $name => $attempt) {
            try {
                $attempt();
                $this->fail('Content SQL was accepted: '.$name);
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, LearnerTables::query($scope, 'notes')->count());
    }

    public function test_personal_caches_are_ciphertext_and_bound_to_the_learner_and_cache_key(): void
    {
        $scope = LearnerScope::of($this->principal($this->student()));
        $other = LearnerScope::of($this->principal($this->student()));
        LearnerCache::put($scope, 'synthetic-cache', ['text' => 'private cache marker'], 60);
        $this->assertStringNotContainsString('private cache marker', Cache::get('synthetic-cache'));
        $this->assertSame(['text' => 'private cache marker'], LearnerCache::get($scope, 'synthetic-cache'));
        $this->assertThrows(fn () => LearnerCache::get($other, 'synthetic-cache'), RuntimeException::class);
        Cache::put('other-cache', Cache::get('synthetic-cache'), 60);
        $this->assertThrows(fn () => LearnerCache::get($scope, 'other-cache'), RuntimeException::class);
    }

    public function test_account_transaction_guard_can_be_enabled_in_tests(): void
    {
        $users = DB::table('users')->count();
        $keys = DB::connection('mysql_keys')->table('learner_keys')->count();
        config(['vistud.testing.enforce_account_transaction_guard' => true]);
        $this->assertThrows(fn () => DB::transaction(fn () => app(AccountFactory::class)->create('Synthetic student', 'guard@example.test', 'Synthetic-password-123!', true, 'UTC', null)), LogicException::class);
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame($keys, DB::connection('mysql_keys')->table('learner_keys')->count());
    }

    public function test_a_destroyed_key_makes_content_unreadable_without_reprovisioning_it(): void
    {
        $by = $this->principal($this->student());
        $scope = LearnerScope::of($by);
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Course']);
        $note = app(Notes::class)->create($by, 'workspace', $workspace->id, 'Synthetic private note');
        DB::connection('mysql_keys')->table('learner_keys')->where('learner_id', $scope->learnerId)->delete();
        app()->forgetScopedInstances();
        $this->assertThrows(fn () => app(Notes::class)->open($by, $note->id), Unavailable::class);
        $this->assertSame(0, DB::connection('mysql_keys')->table('learner_keys')->where('learner_id', $scope->learnerId)->count());
    }

    public function test_insert_only_reference_copies_reserve_new_identities_and_refuse_ciphertext_swaps(): void
    {
        $by = $this->principal($this->student());
        $scope = LearnerScope::of($by);
        app(Workspaces::class)->create($by, ['name' => 'Course']);
        $entryId = LearnerTables::query($scope, 'journal_entries')->value('id');
        $row = ['entry_id' => $entryId, 'role' => 'target', 'ref_type' => 'source', 'ref_id' => 'synthetic-source', 'locator' => 'private locator'];
        LearnerTables::insert($scope, 'journal_refs', $row);
        $first = LearnerTables::query($scope, 'journal_refs')->where('ref_id', 'synthetic-source')->first();
        $copy = (array) $first;
        unset($copy['id']);
        LearnerTables::insert($scope, 'journal_refs', $copy);
        $raw = DB::table('journal_refs')->where('ref_id', 'synthetic-source')->orderBy('id')->get();
        $this->assertNotSame($raw[0]->content_identity, $raw[1]->content_identity);
        $this->assertNotSame($raw[0]->locator, $raw[1]->locator);
        $wrong = (array) $raw[1];
        $wrong['locator'] = $raw[0]->locator;
        $this->assertThrows(fn () => app(ContentCipher::class)->decodeRow($scope, 'journal_refs', $wrong), RuntimeException::class);
    }
}
