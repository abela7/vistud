<?php

namespace App\Mcp;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;

/**
 * Notes edited in two places (docs/specs/mcp-connections-plan.md §3.7): which chat app wrote a note's current version,
 * and whether any app is working now. The note page uses them to name the app on a conflict and to check for a newer
 * version every 30 seconds while an app is active, until pushed updates (Reverb) replace the poll.
 */
final class NoteApps
{
    /** An app used this recently is "active": the note page polls while it is. */
    public const ACTIVE_MINUTES = 10;

    /** The company of the app whose edit is the note's version `$version`, or null when the student or the in-app tutor wrote it. */
    public function wrote(Principal $by, string $noteId, int $version): ?string
    {
        $scope = Guard::learner($by);
        // elapsed: an edit is remembered for a day of real time.
        $call = LearnerTables::query($scope, 'mcp_calls')->where('outcome', Calls::OK)->where('created_at', '>=', now()->subHours(24))
            ->whereJsonContains('effects->notes', ['id' => $noteId, 'version' => $version])
            ->orderByDesc('created_at')->first(['connection_id']);
        if ($call === null) {
            return null;
        }
        $provider = LearnerTables::query($scope, 'mcp_connections')->where('id', $call->connection_id)->value('provider');

        return $provider === null ? null : Provider::company((string) $provider);
    }

    /** Whether any of the student's apps was used in the last ten minutes. */
    public function active(Principal $by): bool
    {
        // elapsed: "used lately" is ten minutes of real time.
        return LearnerTables::query(Guard::learner($by), 'mcp_connections')->whereNull('revoked_at')->where('last_used_at', '>=', now()->subMinutes(self::ACTIVE_MINUTES))->exists();
    }
}
