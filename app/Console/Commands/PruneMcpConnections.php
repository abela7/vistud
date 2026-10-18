<?php

namespace App\Console\Commands;

use App\Mcp\Connections;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nightly upkeep of AI-app connections (docs/specs/mcp-connections-plan.md §3.4, M6.1): a connection unused for
 * config('mcp.revoke_days') is cut off (its tokens with it), and call records older than 400 days are deleted.
 */
#[Signature('vistud:mcp:prune')]
#[Description('Cut AI-app connections unused for months and delete old MCP call records')]
class PruneMcpConnections extends Command
{
    /** How long a call record is kept. */
    public const CALL_DAYS = 400;

    public function handle(): int
    {
        // elapsed: a retention window, counted in real time.
        $unusedSince = now()->subDays((int) config('mcp.revoke_days', 90));
        $callsBefore = now()->subDays(self::CALL_DAYS);
        $revoked = 0;
        $deleted = 0;

        foreach (DB::table('learners')->orderBy('id')->get(['id', 'user_id']) as $learner) {
            $scope = LearnerScope::forJob((string) $learner->id);
            $stale = LearnerTables::query($scope, 'mcp_connections')
                ->whereNull('revoked_at')
                ->where(fn ($q) => $q->where('last_used_at', '<', $unusedSince)->orWhere(fn ($q) => $q->whereNull('last_used_at')->where('consented_at', '<', $unusedSince)))
                ->get();
            foreach ($stale as $row) {
                Connections::revokeOne($scope, (string) $learner->user_id, $row, 'unused');
                $revoked++;
            }
            $deleted += LearnerTables::query($scope, 'mcp_calls')->where('created_at', '<', $callsBefore)->delete();
        }

        $this->info("Cut {$revoked} unused ".($revoked === 1 ? 'connection' : 'connections').", deleted {$deleted} old call ".($deleted === 1 ? 'record' : 'records').'.');

        return self::SUCCESS;
    }
}
