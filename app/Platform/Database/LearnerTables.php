<?php

namespace App\Platform\Database;

use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Errors\Forbidden;
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
    /** Kept in a physically separate database and migration path. */
    public const KEY_TABLES = ['learner_keys'];

    public static function keyRowsForMaintenance(Principal $by): Builder
    {
        if (! $by->isSystem()) {
            throw new Forbidden('system_required', 'This needs the console.');
        }
        self::assertKeyDatabase();

        return DB::connection('mysql_keys')->table('learner_keys');
    }

    public static function assertKeyDatabase(): void
    {
        $keys = config('database.connections.mysql_keys.database');
        if (! is_string($keys) || $keys === '' || $keys === config('database.connections.mysql.database')
            || $keys !== config('database.connections.mysql_keys_owner.database')) {
            throw new \RuntimeException('The learner key database must be separate from the content database.');
        }
    }

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
        'modules',
        'folders',
        'notes',
        'note_versions',
        'content_tombstones',
        'files',
        'topics',
        'questions',
        'findings',
        'links',
        'activities',
        'activity_items',
        'activity_members',
        'activity_sources',
        'activity_understandings',
        'activity_log',
        'instructions',
        'study_sessions',
        'session_segments',
        'flashcards',
        'engine_settings',
        'engine_threads',
        'engine_messages',
        'engine_jobs',
        'course_profiles',
        'learner_profiles',
        'file_digests',
        'topic_suggestions',
        'module_briefs',
        'folder_briefs',
        'quizzes',
        'tutor_memories',
        'class_sessions',
        'class_recordings',
        'class_transcripts',
        'class_materials',
        'class_reviews',
        'outbox',
        'canonical_files',
        'mcp_connections',
        'mcp_calls',
        'mcp_confirmations',
    ];

    /** `$connection`: another than the default, such as the owner's for what the runtime user may not do (journal rebuilds). */
    public static function query(LearnerScope $scope, string $table, ?string $connection = null): Builder
    {
        if (in_array($table, self::KEY_TABLES, true)) {
            self::assertKeyDatabase();
            if ($connection !== null && $connection !== 'mysql_keys') {
                throw new InvalidArgumentException('Learner keys use only mysql_keys.');
            }

            return DB::connection('mysql_keys')->table($table)->where($table.'.learner_id', $scope->learnerId);
        }
        if (! in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException("'{$table}' is not a registered learner table.");
        }

        $database = DB::connection($connection);
        $query = ContentColumns::fields($table) === [] ? $database->query()
            : (new ContentQuery($database, $database->getQueryGrammar(), $database->getPostProcessor()))->forContent($scope, $table);

        return $query->from($table)->where($table.'.learner_id', $scope->learnerId);
    }

    /** Erasure must work without decrypting content, including after key destruction. */
    public static function forErasure(Principal $by, LearnerScope $scope, string $table): Builder
    {
        if (! $by->isSystem() || ! in_array($table, self::TABLES, true)) {
            throw new Forbidden('system_required', 'This needs the maintenance worker.');
        }

        return DB::connection(config('vistud.database.owner_connection'))->table($table)->where('learner_id', $scope->learnerId);
    }

    /** Shared rows in canonical_files (learner_id is null and visibility is shared). */
    public static function shared(string $table, ?string $connection = null): Builder
    {
        if ($table !== 'canonical_files') {
            throw new InvalidArgumentException("'{$table}' is not a shared learner table.");
        }

        return DB::connection($connection)->table('canonical_files')
            ->whereNull('canonical_files.learner_id')
            ->where('canonical_files.visibility', 'shared');
    }

    /** Insert rows, forcing learner_id to the scope's learner. */
    public static function insert(LearnerScope $scope, string $table, array $rows, ?string $connection = null): void
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException("'{$table}' is not a registered learner table.");
        }
        if ($rows === []) {
            return;
        }
        $rows = array_is_list($rows) ? $rows : [$rows];

        self::query($scope, $table, $connection)->insert(array_map(
            fn (array $row) => ['learner_id' => $scope->learnerId] + $row,
            $rows,
        ));
    }

    /** Raw rows only for the reviewed owner-run backfill, never a request path. */
    public static function contentForMaintenance(Principal $by, LearnerScope $scope, string $table): Builder
    {
        if (! $by->isSystem() || ContentColumns::fields($table) === []) {
            throw new Forbidden('system_required', 'This needs the console.');
        }

        return DB::connection(config('vistud.database.owner_connection'))->table($table)->where('learner_id', $scope->learnerId);
    }

    public static function contentLearnersForMaintenance(Principal $by, string $table): array
    {
        if (! $by->isSystem() || ContentColumns::fields($table) === []) {
            throw new Forbidden('system_required', 'This needs the console.');
        }

        return DB::connection(config('vistud.database.owner_connection'))->table($table)->distinct()->pluck('learner_id')->all();
    }

    public static function contentOrphansForMaintenance(Principal $by, string $table, ?string $learnerId): Builder
    {
        if (! $by->isSystem() || ContentColumns::fields($table) === []) {
            throw new Forbidden('system_required', 'This needs the console.');
        }
        $database = DB::connection(config('vistud.database.owner_connection'));
        if ($learnerId !== null && $database->table('learners')->where('id', $learnerId)->exists()) {
            throw new Forbidden('learner_exists', 'Use the learner scope.');
        }

        return $database->table($table)->where('learner_id', $learnerId);
    }
}
