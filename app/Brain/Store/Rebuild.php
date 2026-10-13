<?php

namespace App\Brain\Store;

use App\Brain\Projection\ProjectionOptions;
use App\Brain\Projection\ProjectionRunner;
use App\Brain\Writer\JournalWriter;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Checks, and on request rebuilds, what is derived from a learner's journal (ADR 0002 §7): the reference index
 * (journal_refs), worked out again from each stored entry's kind, links and body, and the projection, replayed twice
 * from the entries to show the rules give the same state each time, then kept as the latest snapshot.
 *
 * The journal itself is never touched. The runtime user may only add to journal_refs, so a repair runs as the
 * schema owner, in one transaction.
 */
final class Rebuild
{
    public function __construct(private ProjectionRunner $projections) {}

    /**
     * @return array{entries: int, refs: int, missing: int, extra: int, repaired: bool, position: int, deterministic: bool}
     */
    public function run(LearnerScope $scope, bool $repair = false): array
    {
        $owner = (string) config('vistud.database.owner_connection');
        $expected = [];
        $entries = 0;
        foreach (LearnerTables::query($scope, 'journal_entries')->orderBy('position')->get(['id', 'kind', 'links', 'body']) as $row) {
            $entries++;
            $spec = ['kind' => $row->kind, 'links' => json_decode((string) $row->links, true) ?: [], 'body' => json_decode((string) $row->body, true) ?: []];
            foreach (JournalWriter::refsOf($spec) as $pair) {
                $ref = JournalStore::refRow($row->id, $pair);
                $expected[self::key($ref)][] = $ref;
            }
        }

        $stored = [];
        foreach (LearnerTables::query($scope, 'journal_refs')->orderBy('id')->get() as $row) {
            $stored[self::key((array) $row)][] = (int) $row->id;
        }

        // The same row may rightly be there more than once (an entry naming a thing twice): counted, not just seen.
        $missing = [];
        $extra = [];
        foreach ($expected as $key => $rows) {
            $missing = [...$missing, ...array_slice($rows, count($stored[$key] ?? []))];
        }
        foreach ($stored as $key => $ids) {
            $extra = [...$extra, ...array_slice($ids, count($expected[$key] ?? []))];
        }

        $repaired = false;
        if ($repair && ($missing !== [] || $extra !== [])) {
            DB::connection($owner)->transaction(function () use ($scope, $owner, $missing, $extra) {
                if ($extra !== []) {
                    LearnerTables::query($scope, 'journal_refs', $owner)->whereIn('id', $extra)->delete();
                }
                LearnerTables::insert($scope, 'journal_refs', $missing, $owner);
            });
            $repaired = true;
        }

        $now = new DateTimeImmutable;
        $first = $this->projections->project($scope, new ProjectionOptions($now));
        $second = $this->projections->project($scope, new ProjectionOptions($now));
        $deterministic = $first === $second;
        if ($repair && $deterministic) {
            $this->projections->refresh($scope, new ProjectionOptions($now));
        }

        return [
            'entries' => $entries,
            'refs' => array_sum(array_map('count', $expected)),
            'missing' => count($missing),
            'extra' => count($extra),
            'repaired' => $repaired,
            'position' => (int) ($first['position'] ?? 0),
            'deterministic' => $deterministic,
        ];
    }

    /** @param array<string, mixed> $ref */
    private static function key(array $ref): string
    {
        return implode("\0", [$ref['entry_id'], $ref['role'], $ref['ref_type'], $ref['ref_id'], $ref['locator'] ?? '']);
    }
}
