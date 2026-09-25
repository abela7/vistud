<?php

namespace Tests\Feature\Brain\Store;

use App\Brain\Writer\JournalWriter;
use App\Platform\Database\LearnerTables;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;
use Throwable;

/**
 * WP3 acceptance: two processes appending to one learner at the same time
 * get distinct, gap-free positions (ADR 0002 §3: strictly increasing within
 * a learner). Uses real processes, committed data and two connections.
 */
class ConcurrentAppendTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private const PER_WORKER = 500;

    protected $connectionsToTransact = [];

    protected function tearDown(): void
    {
        $owner = DB::connection(config('vistud.database.owner_connection'));
        foreach (['journal_refs', 'journal_mentions', 'journal_content', 'journal_entries', 'audit_log', 'learners', 'user_roles', 'users'] as $table) {
            $owner->table($table)->delete();
        }

        parent::tearDown();
    }

    public function test_two_processes_appending_at_once_get_distinct_gap_free_positions(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Needs the pcntl extension (CI installs it).');
        }

        $scope = $this->learnerScopeOf($this->student());
        app(JournalWriter::class)->appendBatch($scope, $this->setupEntries());

        // Children must not share the parent's database socket.
        DB::disconnect();
        $results = [];
        $pids = [];
        foreach (['A', 'B'] as $worker) {
            $results[$worker] = tempnam(sys_get_temp_dir(), "vistud-append-{$worker}-");
            $pid = pcntl_fork();
            if ($pid === 0) {
                $outcome = 'ok';
                try {
                    DB::purge();
                    $writer = app(JournalWriter::class);
                    for ($i = 0; $i < self::PER_WORKER; $i++) {
                        $at = sprintf('2026-10-15T14:%02d:%02d+01:00', intdiv($i, 60) % 60, $i % 60);
                        $writer->append($scope, $this->attempt($scope, "{$worker}-{$i}", 'TK-1', 'correct', $at));
                    }
                } catch (Throwable $e) {
                    $outcome = $e::class.': '.$e->getMessage();
                }
                file_put_contents($results[$worker], $outcome);
                // Leave without running PHPUnit's shutdown handlers in the child.
                posix_kill(posix_getpid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::reconnect();

        foreach ($results as $worker => $file) {
            $this->assertSame('ok', file_get_contents($file), "Worker {$worker} failed.");
            unlink($file);
        }

        $total = 3 + 2 * self::PER_WORKER;
        $positions = LearnerTables::query($scope, 'journal_entries')->orderBy('position')->pluck('position')->map(fn ($p) => (int) $p)->all();
        $this->assertSame(range(1, $total), $positions);
        $this->assertSame($total, (int) DB::table('learners')->where('id', $scope->learnerId)->value('journal_position'));

        // The two workers really did interleave.
        $order = LearnerTables::query($scope, 'journal_entries')->where('position', '>', 3)->orderBy('position')->pluck('id')
            ->map(fn (string $id) => $id[0])->implode('');
        $this->assertGreaterThan(1, preg_match_all('/AB|BA/', $order));
    }
}
