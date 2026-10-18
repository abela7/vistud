<?php

namespace App\Jobs;

use App\Mcp\Connections;
use App\Platform\Access\LearnerScope;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * Cuts every AI app of one account after an account event: a suspension, a deletion request, a 2FA reset or a new
 * password (docs/specs/mcp-connections-plan.md M6.1). Run at once (dispatchSync), never queued: the apps must stop
 * before the request that caused it answers. An account with no learner (an admin only) has its tokens revoked.
 */
final class RevokeConnections
{
    use Dispatchable;

    public function __construct(public string $userId, public string $reason) {}

    public function handle(): void
    {
        $learnerId = DB::table('learners')->where('user_id', $this->userId)->value('id');
        if (! is_string($learnerId)) {
            Connections::revokeTokens($this->userId, null);

            return;
        }
        Connections::revokeEverything(LearnerScope::forJob($learnerId), $this->userId, $this->reason);
    }
}
