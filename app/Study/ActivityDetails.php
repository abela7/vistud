<?php

namespace App\Study;

use Illuminate\Support\Carbon;

/** An assignment or task, as the screens see it. $dueOn is a date (Y-m-d) or null. */
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
    ) {}

    public function kindLabel(): string
    {
        return Activities::KINDS[$this->kind] ?? ucfirst(str_replace('_', ' ', $this->kind));
    }

    /** Due before today and not done. */
    public function overdue(?Carbon $today = null): bool
    {
        return $this->status !== 'done' && $this->dueOn !== null && $this->dueOn < ($today ?? Carbon::today())->toDateString();
    }

    /** "Due today", "Due tomorrow", "Due Fri 3 Oct", "Was due 2 Oct", or null. */
    public function dueWords(?Carbon $today = null): ?string
    {
        if ($this->dueOn === null) {
            return null;
        }
        $today ??= Carbon::today();
        $due = Carbon::parse($this->dueOn);
        $days = (int) $today->diffInDays($due, false);

        return match (true) {
            $this->overdue($today) => 'Was due '.$due->format('j M'),
            $days === 0 => 'Due today',
            $days === 1 => 'Due tomorrow',
            $days < 7 => 'Due '.$due->format('D j M'),
            $due->year === $today->year => 'Due '.$due->format('j M'),
            default => 'Due '.$due->format('j M Y'),
        };
    }
}
