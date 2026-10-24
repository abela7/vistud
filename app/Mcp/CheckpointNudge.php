<?php

namespace App\Mcp;

use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Study\SessionDetails;
use Carbon\CarbonImmutable;

/**
 * A reminder in a save's answer when a chat app's study session has gone a while without a checkpoint (the owner's
 * ask, 2026-10-24). A chat app's conversation is never in ViStud, so its checkpoints and end summary are all the
 * next tutor (another app, or ViStud's own) has to pick up from; apps follow a tool's answer better than a rule.
 */
final class CheckpointNudge
{
    public const MINUTES = 20;

    /** The line to add, or null while the last checkpoint (or the session's start) is recent enough. */
    public static function for(LearnerScope $scope, SessionDetails $session, string $tool): ?string
    {
        if (! $session->isOpen() || in_array($tool, ['set_checkpoint', 'end_session', 'start_session'], true)) {
            return null;
        }
        $last = LearnerTables::query($scope, 'mcp_calls')->where('tool', 'set_checkpoint')->where('outcome', Calls::OK)
            ->where('target_type', 'session')->where('target_id', $session->id)->max('created_at');
        // elapsed: minutes of real time since the last checkpoint, not a calendar step.
        $since = CarbonImmutable::parse($last ?? $session->startedAt, 'UTC');
        if ($since->greaterThan(now()->subMinutes(self::MINUTES))) {
            return null;
        }

        return 'Reminder: no checkpoint for '.self::MINUTES.'+ minutes. Call set_checkpoint now with where the session stands and what comes next, so the next tutor can pick up from here.';
    }
}
