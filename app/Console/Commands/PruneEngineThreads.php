<?php

namespace App\Console\Commands;

use App\Engine\Spent;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes engine_threads rows whose course, session, topic or assignment is gone, keeping what their messages cost
 * in the month's usage through Spent::forgetMessages (docs/specs/mcp-connections-plan.md §4 M6.0).
 */
#[Signature('vistud:engine:prune-threads')]
#[Description('Delete engine_threads rows whose parent course, session, topic or assignment is gone')]
class PruneEngineThreads extends Command
{
    public function handle(): int
    {
        $pruned = 0;
        foreach (DB::table('learners')->orderBy('id')->pluck('id') as $learnerId) {
            $scope = LearnerScope::forJob((string) $learnerId);
            $pruned += self::pruneFor($scope);
        }

        $this->info("Pruned {$pruned} ".($pruned === 1 ? 'thread' : 'threads').'.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    public static function orphanIds(LearnerScope $scope): array
    {
        $workspaces = array_flip(LearnerTables::query($scope, 'workspaces')->pluck('id')->map('strval')->all());
        $sessions = array_flip(LearnerTables::query($scope, 'study_sessions')->pluck('id')->map('strval')->all());
        $topics = array_flip(LearnerTables::query($scope, 'topics')->pluck('id')->map('strval')->all());
        $activities = array_flip(LearnerTables::query($scope, 'activities')->pluck('id')->map('strval')->all());

        $orphans = [];
        foreach (LearnerTables::query($scope, 'engine_threads')->get(['id', 'workspace_id', 'session_id', 'topic_id', 'activity_id', 'purpose']) as $row) {
            $missingWorkspace = $row->workspace_id !== null && ! isset($workspaces[(string) $row->workspace_id]);
            $missingSession = $row->session_id !== null && ! isset($sessions[(string) $row->session_id]);
            $missingTopic = $row->topic_id !== null && ! isset($topics[(string) $row->topic_id]);
            $missingActivity = $row->activity_id !== null && ! isset($activities[(string) $row->activity_id]);
            $unattached = $row->purpose === null && $row->session_id === null && $row->topic_id === null && $row->activity_id === null;

            if ($missingWorkspace || $missingSession || $missingTopic || $missingActivity || $unattached) {
                $orphans[] = (string) $row->id;
            }
        }

        return $orphans;
    }

    public static function pruneFor(LearnerScope $scope): int
    {
        $ids = self::orphanIds($scope);
        if ($ids === []) {
            return 0;
        }
        Spent::forgetMessages($scope, $ids);
        LearnerTables::query($scope, 'engine_threads')->whereIn('id', $ids)->delete();

        return count($ids);
    }
}
