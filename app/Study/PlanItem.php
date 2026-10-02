<?php

namespace App\Study;

/**
 * One line of an assignment's plan (App\Study\Plans): a part (a section or a deliverable), a step (a small thing
 * to do, under a part, under another step, or on its own), a criterion (what it is marked on) or a milestone (a
 * date to reach). $weight is the marks a part or a criterion is worth, as a percentage, or null. $state is todo,
 * doing, stuck or done for a part or a step; not_yet, partly or met for a criterion; pending or achieved for a
 * milestone. $startOn and $dueOn are dates (Y-m-d) or null; $priority is low, medium, high, urgent or null;
 * $labels are short words; $memberId is the team member it is for.
 */
final readonly class PlanItem
{
    /** @param list<string> $labels */
    public function __construct(
        public string $id,
        public string $kind,
        public ?string $parentId,
        public string $title,
        public ?int $weight,
        public string $state,
        public int $position,
        public ?string $startOn = null,
        public ?string $dueOn = null,
        public ?string $priority = null,
        public ?string $notes = null,
        public array $labels = [],
        public ?string $memberId = null,
        public ?string $doneAt = null,
    ) {}

    public function done(): bool
    {
        return in_array($this->state, ['done', 'achieved'], true);
    }
}
