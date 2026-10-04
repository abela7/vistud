<?php

namespace App\Engine;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Unprocessable;
use App\Study\Sessions;
use Carbon\CarbonImmutable;

/**
 * What the engine has cost a student this month, by role (docs/specs/vistud-2-blueprint.md §3.6.1): the tutor's
 * is what its chats' messages cost, the reader's and the helper's what their runs cost (engine_jobs). The month
 * is the student's own, in their time zone, and one limit covers all three. Millionths of a dollar.
 */
final class Usage
{
    public function __construct(private Sessions $sessions) {}

    /** @return array{tutor: int, reader: int, helper: int, total: int} */
    public function month(Principal $by): array
    {
        return $this->since(Guard::learner($by), $this->monthStart($this->sessions->timezone($by)));
    }

    /** What the month has cost all together, for the limit. */
    public function monthTotal(LearnerScope $scope, string $zone): int
    {
        return $this->since($scope, $this->monthStart($zone))['total'];
    }

    /**
     * Refuses a call once the month's limit is reached.
     *
     * @throws Unprocessable
     */
    public function refuseOverMonthCap(LearnerScope $scope, Choices $choices, string $zone, bool $chat = false): void
    {
        if ($choices->monthCapMicros > 0 && $this->monthTotal($scope, $zone) >= $choices->monthCapMicros) {
            $limit = Choices::dollars($choices->monthCapMicros);
            throw new Unprocessable('engine_cap', ($chat ? "This month's chats have reached their limit of {$limit}." : "This month's AI use has reached its limit of {$limit}.").' Raise it in your AI settings to keep going.');
        }
    }

    /** @return array{tutor: int, reader: int, helper: int, total: int} */
    private function since(LearnerScope $scope, CarbonImmutable $since): array
    {
        $jobs = LearnerTables::query($scope, 'engine_jobs')->where('created_at', '>=', $since)->selectRaw('role, sum(cost_micros) as cost')->groupBy('role')->pluck('cost', 'role');
        $usage = [
            'tutor' => (int) LearnerTables::query($scope, 'engine_messages')->where('created_at', '>=', $since)->sum('cost_micros'),
            'reader' => (int) ($jobs[Role::Reader->value] ?? 0),
            'helper' => (int) ($jobs[Role::Helper->value] ?? 0),
        ];

        return $usage + ['total' => array_sum($usage)];
    }

    private function monthStart(string $zone): CarbonImmutable
    {
        return CarbonImmutable::now($zone)->startOfMonth()->utc();
    }
}
