<?php

namespace App\Engine;

use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Platform\Ids;

/**
 * Deletes chat turns and AI runs while keeping what they cost: the month's usage (App\Engine\Usage) is summed from
 * those rows, so deleting them would lower it and let a cleared chat or a deleted course slip past the month's limit.
 * What they cost stays as one row per role and month in engine_jobs, of no course (a course deleted later keeps it).
 */
final class Spent
{
    /** The kind of a row that only carries what deleted turns or runs cost. */
    public const KIND = 'spent_kept';

    /** Deletes the threads' messages; their cost stays in the month's usage. The threads themselves are the caller's. */
    public static function forgetMessages(LearnerScope $scope, array $threadIds): void
    {
        if ($threadIds === []) {
            return;
        }
        $messages = fn () => LearnerTables::query($scope, 'engine_messages')->whereIn('thread_id', $threadIds);
        self::keep($scope, $messages()->where('cost_micros', '>', 0)
            ->selectRaw("'".Role::Tutor->value."' as role, DATE_FORMAT(created_at, '%Y-%m') as month, sum(cost_micros) as cost, max(created_at) as last")
            ->groupBy('month')->get()->all());
        $messages()->delete();
    }

    /** Deletes a course's AI runs; their cost stays in the month's usage. */
    public static function forgetJobs(LearnerScope $scope, string $workspaceId): void
    {
        $jobs = fn () => LearnerTables::query($scope, 'engine_jobs')->where('workspace_id', $workspaceId);
        self::keep($scope, $jobs()->where('cost_micros', '>', 0)
            ->selectRaw("role, DATE_FORMAT(created_at, '%Y-%m') as month, sum(cost_micros) as cost, max(created_at) as last")
            ->groupBy('role', 'month')->get()->all());
        $jobs()->delete();
    }

    /**
     * Deletes a target's AI runs; their cost stays in the month's usage.
     *
     * @param  string|list<string>  $targetIds
     */
    public static function forgetTargetJobs(LearnerScope $scope, string $targetType, string|array $targetIds): void
    {
        $ids = is_array($targetIds) ? array_values(array_unique($targetIds)) : [$targetIds];
        if ($ids === []) {
            return;
        }
        $jobs = fn () => LearnerTables::query($scope, 'engine_jobs')->where('target_type', $targetType)->whereIn('target_id', $ids);
        self::keep($scope, $jobs()->where('cost_micros', '>', 0)
            ->selectRaw("role, DATE_FORMAT(created_at, '%Y-%m') as month, sum(cost_micros) as cost, max(created_at) as last")
            ->groupBy('role', 'month')->get()->all());
        $jobs()->delete();
    }

    /** @param list<object{role: string, cost: int|string, last: string}> $sums */
    private static function keep(LearnerScope $scope, array $sums): void
    {
        LearnerTables::insert($scope, 'engine_jobs', array_map(fn (object $sum) => [
            'id' => Ids::new(), 'workspace_id' => null, 'role' => $sum->role, 'kind' => self::KIND, 'status' => 'done',
            'cost_micros' => (int) $sum->cost, 'created_at' => $sum->last, 'finished_at' => $sum->last,
        ], $sums));
    }
}
