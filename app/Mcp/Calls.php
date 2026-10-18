<?php

namespace App\Mcp;

use App\Platform\Access\LearnerScope;
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
