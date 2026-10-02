<?php

namespace App\Study;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How far an assignment's plan has got: $done of $total things, as a percentage ($percent, weighted by the marks
 * of the parts when every part has some). A part with steps counts by its steps, a part without steps (a question
 * to answer, a slide set to make) as one thing, and a step outside every part as one thing.
 */
final readonly class PlanProgress
{
    /** Steps a day, more than which the pace is a stretch. */
    public const TIGHT_PER_DAY = 4;

    public function __construct(
        public int $done,
        public int $total,
        public int $percent,
        public bool $weighted = false,
    ) {}

    public function left(): int
    {
        return $this->total - $this->done;
    }

    public function started(): bool
    {
        return $this->done > 0;
    }

    public function complete(): bool
    {
        return $this->total > 0 && $this->done === $this->total;
    }

    /**
     * What is left against the time left: "5 left, about 2 a day", "3 left, due in 6 hours", "5 left and the
     * deadline has passed". The tone is ok, tight (more than TIGHT_PER_DAY a day, or more than 3 left on the
     * last day) or late. Null when there is nothing to say: no plan, nothing left, done, or no deadline.
     *
     * @return array{words: string, tone: string}|null
     */
    public function pace(ActivityDetails $activity, ?CarbonInterface $now = null): ?array
    {
        if ($this->total === 0 || $this->complete() || $activity->status === 'done' || $activity->dueOn === null) {
            return null;
        }
        $left = $this->left();
        if ($activity->overdue($now)) {
            return ['words' => "{$left} left and the deadline has passed", 'tone' => 'late'];
        }
        $now = CarbonImmutable::instance($now ?? now())->setTimezone($activity->zone);
        $hours = max(1, (int) ceil($now->diffInMinutes($activity->dueAt(), false) / 60));
        if ($hours < 24) {
            return ['words' => "{$left} left, due in ".$hours.' '.($hours === 1 ? 'hour' : 'hours'), 'tone' => $left > 3 ? 'tight' : 'ok'];
        }
        $days = $hours / 24;
        $perDay = $left / $days;

        return [
            'words' => "{$left} left, about ".max(1, (int) ceil($perDay)).' a day',
            'tone' => $perDay > self::TIGHT_PER_DAY ? 'tight' : 'ok',
        ];
    }
}
