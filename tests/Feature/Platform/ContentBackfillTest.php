<?php

namespace Tests\Feature\Platform;

use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Owner permissions are deliberate: the command converts append-only journal rows too. */
class ContentBackfillTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'mysql_owner');

        return $app;
    }

    private function legacy(): array
    {
        $by = $this->principal($this->student());
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Course']);
        $notes = [];
        for ($i = 0; $i < 3; $i++) {
            $note = app(Notes::class)->create($by, 'workspace', $workspace->id, 'Legacy '.$i);
            DB::table('notes')->where('id', $note->id)->update(['title' => 'Legacy '.$i, 'content_version' => 0]);
            $notes[] = $note;
        }

        return [$by, $notes];
    }

    public function test_dry_run_is_read_only_and_counts_rows_cells_versions_and_learners(): void
    {
        [$by] = $this->legacy();
        $before = DB::table('notes')->get()->all();
        $keys = DB::connection('mysql_keys')->table('learner_keys')->get()->all();
        Cache::put('unrelated', 'keep', 60);
        $this->artisan('vistud:encrypt:backfill', ['--dry-run' => true, '--table' => ['notes']])
            ->expectsOutputToContain('notes: rows=3, cells=3, legacy=3, encrypted=0, changed=0, blocked=0')
            ->expectsOutputToContain('learners=1, changed=0, blocked=0')->assertSuccessful();
        $this->assertEquals($before, DB::table('notes')->get()->all());
        $this->assertEquals($keys, DB::connection('mysql_keys')->table('learner_keys')->get()->all());
        $this->assertSame('keep', Cache::get('unrelated'));
        $this->assertThrows(fn () => LearnerTables::query(LearnerScope::of($by), 'notes')->first(), \RuntimeException::class);
    }

    public function test_a_stopped_backfill_resumes_and_a_second_run_keeps_ciphertext_unchanged(): void
    {
        [$by, $notes] = $this->legacy();
        $options = ['--table' => ['notes'], '--batch' => 1];
        $this->artisan('vistud:encrypt:backfill', $options + ['--limit' => 1])->assertSuccessful();
        $this->assertSame(1, DB::table('notes')->where('content_version', 1)->count());
        $this->artisan('vistud:encrypt:backfill', $options)->assertSuccessful();
        $encrypted = DB::table('notes')->orderBy('id')->get()->all();
        $this->artisan('vistud:encrypt:backfill', $options)->expectsOutputToContain('changed=0')->assertSuccessful();
        $this->assertEquals($encrypted, DB::table('notes')->orderBy('id')->get()->all());
        $scope = LearnerScope::of($by);
        foreach ($notes as $index => $note) {
            $this->assertSame('Legacy '.$index, LearnerTables::query($scope, 'notes')->where('id', $note->id)->value('title'));
        }
        $this->artisan('vistud:encrypt:backfill', $options + ['--verify' => true])->assertSuccessful();
    }

    public function test_composite_journal_keys_resume_and_rollback_restores_exact_values(): void
    {
        [$by] = $this->legacy();
        $scope = LearnerScope::of($by);
        $entry = DB::table('journal_entries')->where('learner_id', $scope->learnerId)->first();
        $plain = [];
        foreach (['alpha', 'beta', 'gamma'] as $field) {
            $plain[] = ['learner_id' => $scope->learnerId, 'entry_id' => $entry->id, 'field' => $field, 'text' => 'legacy '.$field, 'content_version' => 0];
        }
        DB::table('journal_content')->insert($plain);
        $options = ['--table' => ['journal_content'], '--batch' => 1];
        $this->artisan('vistud:encrypt:backfill', $options + ['--limit' => 2])->assertSuccessful();
        $this->artisan('vistud:encrypt:backfill', $options)->assertSuccessful();
        $this->assertSame(['alpha' => 'legacy alpha', 'beta' => 'legacy beta', 'gamma' => 'legacy gamma'], LearnerTables::query($scope, 'journal_content')->orderBy('field')->pluck('text', 'field')->all());
        $this->artisan('vistud:encrypt:backfill', $options + ['--decrypt' => true])->assertSuccessful();
        $this->assertEquals($plain, DB::table('journal_content')->where('entry_id', $entry->id)->orderBy('field')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_a_missing_key_blocks_without_changing_legacy_rows(): void
    {
        [$by] = $this->legacy();
        DB::connection('mysql_keys')->table('learner_keys')->where('learner_id', $by->learnerId)->delete();
        app()->forgetScopedInstances();
        $before = DB::table('notes')->get()->all();
        $this->artisan('vistud:encrypt:backfill', ['--table' => ['notes']])
            ->expectsOutputToContain('blocked=3')->assertFailed();
        $this->assertEquals($before, DB::table('notes')->get()->all());
    }

    public function test_corruption_fails_verification_and_is_never_treated_as_legacy(): void
    {
        [, $notes] = $this->legacy();
        $options = ['--table' => ['notes']];
        $this->artisan('vistud:encrypt:backfill', $options)->assertSuccessful();
        DB::table('notes')->where('id', $notes[0]->id)->update(['title' => 'broken ciphertext']);
        $this->artisan('vistud:encrypt:backfill', $options + ['--verify' => true])->assertFailed();
        $this->artisan('vistud:encrypt:backfill', $options)->assertFailed();
        $this->assertSame('broken ciphertext', DB::table('notes')->where('id', $notes[0]->id)->value('title'));
        $this->assertSame(1, (int) DB::table('notes')->where('id', $notes[0]->id)->value('content_version'));
    }

    public function test_cache_deploy_clears_only_the_named_learner_caches_including_deleted_places(): void
    {
        config(['cache.default' => 'database']);
        Cache::purge();
        $keys = ['topic-again:deleted-learner:deleted-folder', 'topic-review:deleted-learner:deleted-folder', 'engine-trouble:deleted-learner'];
        foreach ($keys as $key) {
            Cache::put($key, ['text' => 'legacy private cache'], 60);
        }
        Cache::put('unrelated-cache', 'keep', 60);
        $this->artisan('vistud:encrypt:clear-text-caches', ['--dry-run' => true])->expectsOutputToContain('learner cache entries=3')->assertSuccessful();
        $this->assertNotNull(Cache::get($keys[0]));
        $this->artisan('vistud:encrypt:clear-text-caches')->assertSuccessful();
        foreach ($keys as $key) {
            $this->assertNull(Cache::get($key));
        }
        $this->assertSame('keep', Cache::get('unrelated-cache'));
    }

    public function test_a_verification_failure_rolls_back_every_field_and_the_version_in_that_row(): void
    {
        [, $notes] = $this->legacy();
        $id = $notes[0]->id;
        $before = (array) DB::table('notes')->where('id', $id)->first();
        $corrupted = false;
        DB::listen(function ($event) use ($id, &$corrupted) {
            if (! $corrupted && str_starts_with($event->sql, 'update `notes`') && str_contains($event->sql, '`content_version`')) {
                $corrupted = true;
                // Fault injection stays inside the row transaction. No trigger
                // DDL, implicit commit or elevated MySQL privilege is needed.
                DB::table('notes')->where('id', $id)->update(['title' => 'synthetic corruption']);
            }
        });
        $this->artisan('vistud:encrypt:backfill', ['--table' => ['notes']])->assertFailed();
        $this->assertTrue($corrupted);
        $this->assertSame($before, (array) DB::table('notes')->where('id', $id)->first());
    }

    public function test_unowned_rows_are_counted_as_blockers_and_never_silently_skipped(): void
    {
        DB::table('outbox')->insert(['learner_id' => null, 'task' => 'synthetic', 'payload' => '[]', 'next_attempt_at' => now(), 'created_at' => now()]);
        $before = DB::table('outbox')->get()->all();
        $this->artisan('vistud:encrypt:backfill', ['--dry-run' => true, '--table' => ['outbox']])
            ->expectsOutputToContain('outbox: rows=1, cells=1, legacy=1, encrypted=0, changed=0, blocked=1')->assertFailed();
        $this->assertEquals($before, DB::table('outbox')->get()->all());
    }
}
