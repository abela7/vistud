<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Engine\Spent;
use App\Engine\Usage;
use App\Platform\Access\Guard;
use App\Platform\Database\LearnerTables;
use App\Platform\Ids;
use App\Study\Capture;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The shell's way to the engine: the models it offers, a word in a session's chat, and data-hygiene commands. */
class EngineCommandsTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_the_models_are_listed_and_a_session_is_spoken_to(): void
    {
        Cache::flush();
        $engine = new Fake;
        $this->app->instance(Engine::class, $engine);

        // One substring per line of output: the harness matches each written line against one expectation.
        $this->artisan('vistud:engine:models', ['--find' => 'quick'])->expectsOutputToContain('$0.10 in · $0.40 out per million tokens')->expectsOutputToContain('1 models.')->assertSuccessful();
        $this->artisan('vistud:engine:models', ['--find' => 'fake/'])->expectsOutputToContain('3 models.')->assertSuccessful();
        $this->artisan('vistud:engine:models', ['--find' => 'nothing-like-it'])->expectsOutputToContain('No model matches.')->assertFailed();

        $ada = $this->student(['email' => 'ada@example.test']);
        $by = $this->principal($ada);
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $session = app(Sessions::class)->start($by, $workspace->id);
        $this->artisan('vistud:engine:ask', ['session' => $session->id, 'text' => 'Hi', '--user' => 'ada@example.test'])->expectsOutputToContain('Add your key')->assertFailed();

        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->artisan('vistud:engine:ask', ['session' => $session->id, 'text' => 'Hi', '--user' => 'ada@example.test'])->expectsOutputToContain('Choose a model')->assertFailed();
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $engine->will(Fake::calls('topics'), Fake::says('Hello, Ada\'s course has no topics yet.', 2_000));
        $this->artisan('vistud:engine:ask', ['session' => $session->id, 'text' => 'Hi', '--user' => 'ada@example.test'])
            ->expectsOutputToContain('Hello, Ada\'s course has no topics yet.')->expectsOutputToContain('Looked up: topics')->expectsOutputToContain('this turn: $0.00')->assertSuccessful();
        $this->artisan('vistud:engine:ask', ['session' => $session->id, 'text' => 'Hi', '--user' => 'nobody@example.test'])->expectsOutputToContain('No account has the email')->assertFailed();
    }

    public function test_prune_threads_and_db_orphans_clean_stale_rows_while_keeping_spent_usage(): void
    {
        $ada = $this->student(['email' => 'ada@example.test']);
        $by = $this->principal($ada);
        $scope = Guard::learner($by);
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $session = app(Sessions::class)->start($by, $workspace->id);

        // Clean database: vistud:db:orphans prints nothing and succeeds.
        $exit = Artisan::call('vistud:db:orphans');
        $this->assertSame(0, $exit);
        $this->assertSame('', trim(Artisan::output()));

        // Seed a valid session thread, a valid ViStud AI thread, and an orphaned thread whose session is gone.
        $validThreadId = Ids::new();
        $vistudThreadId = Ids::new();
        $orphanThreadId = Ids::new();
        $now = now()->format('Y-m-d H:i:s.u');

        LearnerTables::insert($scope, 'engine_threads', [
            ['id' => $validThreadId, 'workspace_id' => $workspace->id, 'session_id' => $session->id, 'topic_id' => null, 'activity_id' => null, 'purpose' => null, 'model' => 'fake/tutor', 'created_at' => $now, 'updated_at' => $now],
            ['id' => $vistudThreadId, 'workspace_id' => null, 'session_id' => null, 'topic_id' => null, 'activity_id' => null, 'purpose' => 'vistud', 'model' => 'fake/tutor', 'created_at' => $now, 'updated_at' => $now],
            ['id' => $orphanThreadId, 'workspace_id' => $workspace->id, 'session_id' => Ids::new(), 'topic_id' => null, 'activity_id' => null, 'purpose' => null, 'model' => 'fake/tutor', 'created_at' => $now, 'updated_at' => $now],
        ]);
        LearnerTables::insert($scope, 'engine_messages', [
            ['id' => Ids::new(), 'thread_id' => $orphanThreadId, 'position' => 1, 'role' => 'assistant', 'content' => 'Hello', 'cost_micros' => 12_500, 'created_at' => $now],
        ]);

        // vistud:db:orphans reports the orphaned thread and fails without --prune.
        $this->artisan('vistud:db:orphans')->expectsOutputToContain('engine_threads: 1 orphaned row')->assertFailed();

        // vistud:engine:prune-threads removes the orphan thread and preserves its 12_500 micros cost in engine_jobs.
        $this->artisan('vistud:engine:prune-threads')->expectsOutputToContain('Pruned 1 thread.')->assertSuccessful();
        $this->assertSame(12_500, app(Usage::class)->month($by)['total']);
        $this->assertSame(1, LearnerTables::query($scope, 'engine_jobs')->where('kind', Spent::KIND)->count());
        $this->assertSame(0, LearnerTables::query($scope, 'engine_messages')->where('thread_id', $orphanThreadId)->count());
        $this->assertCount(2, LearnerTables::query($scope, 'engine_threads')->pluck('id')->all());

        // Seed an orphaned topic row (missing workspace) and prune via vistud:db:orphans --prune.
        LearnerTables::insert($scope, 'topics', [
            'id' => Ids::new(), 'workspace_id' => Ids::new(), 'name' => 'Ghost topic', 'position' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->artisan('vistud:db:orphans')->expectsOutputToContain('topics: 1 orphaned row')->assertFailed();
        $this->artisan('vistud:db:orphans', ['--prune' => true])->expectsOutputToContain('topics: 1 orphaned row (pruned)')->assertSuccessful();

        $exit = Artisan::call('vistud:db:orphans');
        $this->assertSame(0, $exit);
        $this->assertSame('', trim(Artisan::output()));
    }

    public function test_attribution_audit_reattributes_confirmed_legacy_topics_with_journal_amendment(): void
    {
        $ada = $this->student(['email' => 'ada@example.test']);
        $by = $this->principal($ada);
        $scope = Guard::learner($by);
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $topics = app(Topics::class);

        $joins = $topics->create($by, $workspace->id, 'Joins');
        $keys = $topics->create($by, $workspace->id, 'Keys');

        // Simulate legacy state: both were recorded through report() and stamped 'student',
        // but Joins has a matching status fingerprint in study_sessions.captured.
        $topics->report($by, $joins->id, 'understood');
        $topics->report($by, $keys->id, 'understood');
        LearnerTables::query($scope, 'topics')->whereIn('id', [$joins->id, $keys->id])->update(['status_by' => 'student']);

        $session = app(Sessions::class)->start($by, $workspace->id);
        $fp = Capture::fingerprint(['kind' => 'status', 'topic' => 'Joins', 'proposed' => 'understood']);
        LearnerTables::query($scope, 'study_sessions')->where('id', $session->id)->update(['captured' => json_encode([$fp])]);

        // Before the audit, both legacy 'student' rows read as 'stated_unverified'.
        $this->assertSame('stated_unverified', $topics->find($by, $joins->id)->statusSource());
        $this->assertSame('stated_unverified', $topics->find($by, $keys->id)->statusSource());

        $entriesBefore = LearnerTables::query($scope, 'journal_entries')->count();

        // --skip-all lists Joins without changing it.
        $this->artisan('vistud:topics:attribution-audit', ['--skip-all' => true])
            ->expectsOutputToContain('Topic "Joins"')
            ->expectsOutputToContain('Confirmed 0 of 1 topic.')
            ->assertSuccessful();
        $this->assertSame('stated_unverified', $topics->find($by, $joins->id)->statusSource());

        // Confirming Joins updates status_by to 'accepted' and appends an amendment entry (never rewriting).
        $this->artisan('vistud:topics:attribution-audit')
            ->expectsConfirmation("Mark \"Joins\" ({$joins->id}) as accepted?", 'yes')
            ->expectsOutputToContain('Confirmed 1 of 1 topic.')
            ->assertSuccessful();

        $this->assertSame('accepted', $topics->find($by, $joins->id)->statusSource());
        $this->assertSame('stated_unverified', $topics->find($by, $keys->id)->statusSource());
        $this->assertSame($entriesBefore + 1, LearnerTables::query($scope, 'journal_entries')->count());
        $latest = LearnerTables::query($scope, 'journal_entries')->orderByDesc('position')->first();
        $this->assertSame('amendment', $latest->kind);
    }
}
