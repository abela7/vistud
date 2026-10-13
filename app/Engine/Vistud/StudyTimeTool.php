<?php

namespace App\Engine\Vistud;

use App\Engine\Tools\Context;
use App\Engine\Tools\Lookup;
use App\Engine\Tools\Tool;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use Carbon\CarbonImmutable;

/**
 * How much the student studied, across courses: over the last N days (up to three years), in all and by course, and
 * over time: by day for a fortnight, by week up to four months, by month beyond, so the answer stays short.
 */
final class StudyTimeTool implements Tool
{
    public const MAX_DAYS = 1100;

    public function __construct(private Courses $courses) {}

    public function name(): string
    {
        return 'study_time';
    }

    public function description(): string
    {
        return 'How much the student studied over the last N days (default 30, up to 3 years): the total, by course, and over time (by day, week or month). Optionally for one course. Use it for "how much have I studied", streaks of effort, and to fit a plan to the time they really have.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'days' => ['type' => 'integer', 'description' => 'How many days back, 1 to '.self::MAX_DAYS.'.'],
            'course' => ['type' => 'string', 'description' => 'One course\'s name, to see only it.'],
        ], 'additionalProperties' => false];
    }

    public function run(Principal $by, Context $context, array $input): string
    {
        $days = max(1, min(self::MAX_DAYS, (int) ($input['days'] ?? 30)));
        $now = CarbonImmutable::now($context->zone);
        $from = $now->subDays($days)->startOfDay();
        $query = LearnerTables::query(Guard::learner($by), 'study_sessions')->where('started_at', '>=', $from->utc());
        $only = null;
        if (($wanted = Lookup::text($input, 'course')) !== null) {
            $only = $this->courses->find($by, $wanted);
            if (is_string($only)) {
                return $only;
            }
            $query->where('workspace_id', $only->id);
        }
        $names = [];
        foreach ($this->courses->all($by) as $course) {
            $names[$course->id] = $course->name;
        }
        $step = $days <= 14 ? 'day' : ($days <= 120 ? 'week' : 'month');
        $total = 0;
        $sessions = 0;
        $byCourse = [];
        $byTime = [];
        // Only what is added up comes back: the sessions' course, start and seconds.
        foreach ($query->get(['workspace_id', 'started_at', 'study_seconds']) as $row) {
            $seconds = (int) $row->study_seconds;
            $total += $seconds;
            $sessions++;
            $name = $names[(string) $row->workspace_id] ?? 'a removed course';
            $byCourse[$name] = ($byCourse[$name] ?? 0) + $seconds;
            $at = CarbonImmutable::parse((string) $row->started_at, 'UTC')->setTimezone($context->zone);
            $key = match ($step) {
                'day' => $at->toDateString(),
                'week' => 'week of '.$at->startOfWeek()->toDateString(),
                default => $at->format('Y-m'),
            };
            $byTime[$key] = ($byTime[$key] ?? 0) + $seconds;
        }
        arsort($byCourse);
        ksort($byTime);
        $minutes = fn (int $s) => (int) round($s / 60);

        return Lookup::json(array_filter([
            'from' => $from->toDateString(), 'to' => $now->toDateString(), 'course' => $only?->name,
            'sessions' => $sessions, 'minutes' => $minutes($total),
            'by_course' => $only === null && $byCourse !== [] ? array_map($minutes, $byCourse) : null,
            "by_{$step}" => $byTime !== [] ? array_map($minutes, $byTime) : null,
        ], fn ($v) => $v !== null));
    }
}
