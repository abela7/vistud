<?php

namespace Tests\Feature\Brain\Store;

use App\Brain\Projection\ProjectionRunner;
use App\Brain\Store\Rebuild;
use App\Brain\Writer\JournalWriter;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** What is derived from a journal is checked against it, and rebuilt from it (App\Brain\Store\Rebuild). */
class RebuildTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private LearnerScope $scope;

    // The repair runs as the owner, on its own connection: it must see what the test wrote, so nothing waits in a
    // transaction, and the tables are emptied afterwards instead (as ConcurrentAppendTest does).
    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $owner = DB::connection(config('vistud.database.owner_connection'));
        foreach (['projection_snapshots', 'journal_refs', 'journal_mentions', 'journal_content', 'journal_entries', 'audit_log', 'learners', 'user_roles', 'users'] as $table) {
            $owner->table($table)->delete();
        }

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 13:00:00');
        $this->ada = $this->student();
        $this->scope = $this->learnerScopeOf($this->ada);
        app(JournalWriter::class)->appendBatch($this->scope, [
            ...$this->setupEntries(),
            $this->attempt($this->scope, 'E1', 'TK-1', 'incorrect', '2026-10-15T14:12:00+01:00'),
            $this->attempt($this->scope, 'E2', 'TK-1', 'correct', '2026-10-15T14:30:00+01:00'),
        ]);
    }

    /** @return list<string> the index, a line a row, in a stable order */
    private function index(): array
    {
        return LearnerTables::query($this->scope, 'journal_refs')->get()
            ->map(fn ($r) => "{$r->entry_id} {$r->role} {$r->ref_type}:{$r->ref_id} {$r->locator}")->sort()->values()->all();
    }

    public function test_an_index_written_by_the_journal_writer_matches_the_one_rebuilt_from_the_entries(): void
    {
        $result = app(Rebuild::class)->run($this->scope);

        $this->assertSame(5, $result['entries']);
        $this->assertSame(count($this->index()), $result['refs']);
        $this->assertGreaterThan(0, $result['refs']);
        $this->assertSame([0, 0, false, true, 5], [$result['missing'], $result['extra'], $result['repaired'], $result['deterministic'], $result['position']]);
    }

    public function test_a_damaged_index_is_found_and_repaired_as_the_owner_and_a_snapshot_is_kept(): void
    {
        $before = $this->index();
        $owner = (string) config('vistud.database.owner_connection');
        $lost = LearnerTables::query($this->scope, 'journal_refs', $owner)->orderBy('id')->first();
        LearnerTables::query($this->scope, 'journal_refs', $owner)->where('id', $lost->id)->delete();
        LearnerTables::insert($this->scope, 'journal_refs', ['entry_id' => 'E1', 'role' => 'value', 'ref_type' => 'topic', 'ref_id' => 'nowhere', 'locator' => null], $owner);

        $checked = app(Rebuild::class)->run($this->scope);
        $this->assertSame([1, 1, false], [$checked['missing'], $checked['extra'], $checked['repaired']]);
        $this->assertNull(app(ProjectionRunner::class)->latest($this->scope), 'a check alone changes nothing');

        $repaired = app(Rebuild::class)->run($this->scope, repair: true);
        $this->assertTrue($repaired['repaired']);
        $this->assertSame($before, $this->index());
        $this->assertSame(5, app(ProjectionRunner::class)->latest($this->scope)['position']);
        $this->assertSame([0, 0], [app(Rebuild::class)->run($this->scope)['missing'], app(Rebuild::class)->run($this->scope)['extra']]);
    }

    public function test_the_command_fails_on_a_damaged_index_until_it_is_repaired(): void
    {
        $this->artisan('vistud:journal:rebuild')->assertSuccessful();

        $owner = (string) config('vistud.database.owner_connection');
        LearnerTables::query($this->scope, 'journal_refs', $owner)->where('entry_id', 'E2')->delete();
        $this->artisan('vistud:journal:rebuild')->expectsOutputToContain('need attention')->assertFailed();
        $this->artisan('vistud:journal:rebuild', ['email' => $this->ada->email, '--repair' => true])->expectsOutputToContain('repaired')->assertSuccessful();
        $this->artisan('vistud:journal:rebuild')->assertSuccessful();
        $this->artisan('vistud:journal:rebuild', ['email' => 'nobody@example.com'])->assertFailed();
    }
}
