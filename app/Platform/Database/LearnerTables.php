<?php

namespace App\Platform\Database;

use App\Platform\Access\LearnerScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The learner-isolation layer for data access (ADR 0001 I3, ADR 0003 §10.4).
 *
 * Every table that holds a learner's private data is listed here, and code
 * reaches those tables only through query(), which always adds the learner
 * filter. tests/Architecture/LearnerIsolationTest.php fails the build if any
 * other code names one of these tables directly, or if a listed table lacks
 * a learner_id column.
 *
 * Adding a learner table is a contract change (docs/architecture/contracts.md).
 */
final class LearnerTables
{
    /** @var list<string> */
    public const TABLES = [
        'journal_entries',
        'journal_content',
        'journal_mentions',
        'journal_refs',
        'journal_blocks',
        'redactions',
        'projection_snapshots',
        'workspaces',
    ];

    public static function query(LearnerScope $scope, string $table): Builder
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException("'{$table}' is not a registered learner table.");
        }

        return DB::table($table)->where($table.'.learner_id', $scope->learnerId);
    }

    /** Insert rows, forcing learner_id to the scope's learner. */
    public static function insert(LearnerScope $scope, string $table, array $rows): void
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException("'{$table}' is not a registered learner table.");
        }
        if ($rows === []) {
            return;
        }
        $rows = array_is_list($rows) ? $rows : [$rows];

        DB::table($table)->insert(array_map(
            fn (array $row) => ['learner_id' => $scope->learnerId] + $row,
            $rows,
        ));
    }
}
