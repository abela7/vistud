<?php

namespace App\Study;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * An assignment or task, as the screens see it. $dueOn is a date (Y-m-d) or null; $dueTime the time on that day
 * (H:i) or null for the end of the day, both in the student's time zone ($zone). $folderId is its own folder.
 */
final readonly class ActivityDetails
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $moduleId,
        public string $kind,
        public string $title,
        public ?string $dueOn,
        public string $status,
        public string $createdAt,
        public string $updatedAt,
        public ?string $dueTime = null,
        public ?string $folderId = null,
        public string $zone = 'UTC',
    ) {}

    public function kindLabel(): string
    {
        return Activities::KINDS[$this->kind] ?? ucfirst(str_replace('_', ' ', $this->kind));
    }

    /** The deadline: its day at its time, or at the very end of the day, in the student's time zone. */
    public function dueAt(): ?CarbonImmutable
    {
        if ($this->dueOn === null) {
            return null;
        }

        return CarbonImmutable::parse($this->dueOn.' '.($this->dueTime ?? '23:59:59'), $this->zone);
    }

    /** Past its deadline and not done. A deadline without a time is past once its day is. */
    public function overdue(?CarbonInterface $now = null): bool
    {
        if ($this->status === 'done' || $this->dueOn === null) {
            return false;
        }

        return $this->dueTime === null
            ? $this->dueOn < $this->now($now)->toDateString()
            : $this->dueAt()->lessThan($this->now($now));
    }

    /** "Due today, 23:59", "Due tomorrow", "Due Fri 3 Oct", "Was due 2 Oct, 14:00", or null. */
    public function dueWords(?CarbonInterface $now = null): ?string
    {
        if ($this->dueOn === null) {
            return null;
        }
        $today = $this->now($now)->startOfDay();
        $due = CarbonImmutable::parse($this->dueOn, $this->zone);
        $days = (int) $today->diffInDays($due, false);
        $at = $this->dueTime === null ? '' : ', '.$this->dueTime;

        return match (true) {
            $this->overdue($now) => 'Was due '.$due->format('j M').$at,
            $days === 0 => 'Due today'.$at,
            $days === 1 => 'Due tomorrow'.$at,
            $days < 7 => 'Due '.$due->format('D j M').$at,
            $due->year === $today->year => 'Due '.$due->format('j M').$at,
            default => 'Due '.$due->format('j M Y').$at,
        };
    }

    /** How long is left, or how late it is: "3 days left", "5 hours left", "20 minutes left", "2 days late"; null when done or with no deadline. */
    public function timeLeft(?CarbonInterface $now = null): ?string
    {
        if ($this->dueOn === null || $this->status === 'done') {
            return null;
        }
        $now = $this->now($now);
        $due = $this->dueAt();
        $late = $due->lessThan($now);
        $minutes = (int) abs($now->diffInMinutes($due, false));
        [$count, $unit] = match (true) {
            $minutes < 60 => [max(1, $minutes), 'minute'],
            $minutes < 48 * 60 => [intdiv($minutes, 60), 'hour'],
            default => [intdiv($minutes, 24 * 60), 'day'],
        };
        $words = $count.' '.$unit.($count === 1 ? '' : 's');

        return $late ? "{$words} late" : "{$words} left";
    }

    private function now(?CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now ?? Carbon::now())->setTimezone($this->zone);
    }
}
