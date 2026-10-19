<?php

namespace App\Mcp;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Ids;

/**
 * One row per MCP call (docs/specs/mcp-connections-plan.md §3.4): which tool, how it went, what it was about (a type
 * and an id), and how much text went back. Never the text itself. It is the disclosure list on the Connections screen
 * and, from M6.3, the source for Undo. Kept 400 days (vistud:mcp:prune).
 */
final class Calls
{
    public const OK = 'ok';

    public const ERROR = 'error';

    public const NOT_FOUND = 'not_found';

    public const NOT_ALLOWED = 'not_allowed';

    /** @param  array<string, mixed>|null  $effects  kinds, counts, ids and status codes only */
    public static function record(LearnerScope $scope, object $connection, string $tool, string $outcome, ?string $targetType, ?string $targetId, int $chars, ?array $effects = null): void
    {
        LearnerTables::insert($scope, 'mcp_calls', [
            'id' => Ids::new(),
            'connection_id' => $connection->id,
            'tool' => mb_substr($tool, 0, 60),
            'outcome' => $outcome,
            'target_type' => $targetType,
            'target_id' => $targetId !== null ? mb_substr($targetId, 0, 64) : null,
            'chars' => max(0, $chars),
            'effects' => $effects === null ? null : json_encode($effects, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /**
     * What a tool did, from App\Engine\Tools\Effects::take(), reduced to kinds, counts, ids and status codes: never a
     * title, a reason, a memory or a log text. `rows` is how many things it wrote, for the daily cap.
     *
     * @param  array<string, mixed>  $taken
     * @return array<string, mixed>
     */
    public static function reduce(array $taken): array
    {
        $saved = array_map('intval', (array) ($taken['saved'] ?? []));
        $statuses = array_map(fn (array $s) => array_intersect_key($s, array_flip(['topic_id', 'from', 'to', 'applied', 'before'])), (array) ($taken['statuses'] ?? []));
        $quizzes = array_map(fn (array $q) => array_intersect_key($q, array_flip(['id', 'kind', 'score', 'asked'])), (array) ($taken['quizzes'] ?? []));
        $notes = array_map(fn (array $n) => array_intersect_key($n, array_flip(['id', 'version'])), (array) ($taken['notes'] ?? []));
        $logged = array_map(fn (array $l) => array_intersect_key($l, array_flip(['id', 'stage', 'kind'])), (array) ($taken['log_entries'] ?? []));

        return array_filter([
            'saved' => $saved,
            'saved_ids' => (array) ($taken['saved_ids'] ?? []),
            'statuses' => $statuses,
            'quizzes' => $quizzes,
            'notes' => $notes,
            'topic_id' => $taken['topic_id'] ?? null,
            'log_entries' => $logged,
            'asked' => isset($taken['asked']) ? count((array) $taken['asked']) : null,
            'rows' => array_sum($saved) + count($statuses) + array_sum(array_map(fn (array $q) => (int) ($q['asked'] ?? 1), $quizzes)) + count($notes) + count($logged),
        ], fn ($v) => $v !== null && $v !== []);
    }

    /** A write refused at the daily cap: one call row, and `mcp.rate_limited` in the audit log (no content). */
    public static function limitReached(Principal $by, object $connection, string $tool): void
    {
        self::record(Guard::learner($by), $connection, $tool, self::NOT_ALLOWED, null, null, 0, ['reason' => 'rate_limited']);
        app(AuditLog::class)->record($by, AuditAction::MCP_RATE_LIMITED, 'mcp_connection', (string) $connection->id, ['tool' => $tool]);
    }

    /** How many things the student's AI apps wrote in the last 24 hours, for the daily cap. */
    public static function rowsToday(LearnerScope $scope): int
    {
        // elapsed: a rolling 24-hour window, counted in real time.
        $since = now()->subHours(24);

        return (int) LearnerTables::query($scope, 'mcp_calls')->where('created_at', '>=', $since)->where('outcome', self::OK)
            ->get(['effects'])->sum(fn (object $row) => (int) (json_decode((string) $row->effects, true)['rows'] ?? 0));
    }

    /** The earlier call with this capture key, in the last 24 hours, or null. */
    public static function byCaptureKey(LearnerScope $scope, object $connection, string $tool, string $key): ?object
    {
        // elapsed: a resent capture is recognised for a day.
        return LearnerTables::query($scope, 'mcp_calls')
            ->where('connection_id', $connection->id)
            ->where('tool', $tool)
            ->where('created_at', '>=', now()->subHours(24))
            ->where('effects->capture_key', $key)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * The newest calls of the student's connections, for the Connections screen.
     *
     * @return list<object{tool: string, outcome: string, target_type: ?string, created_at: string, connection_id: string}>
     */
    public static function recent(LearnerScope $scope, int $limit = 20): array
    {
        return LearnerTables::query($scope, 'mcp_calls')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['tool', 'outcome', 'target_type', 'created_at', 'connection_id'])
            ->all();
    }
}
