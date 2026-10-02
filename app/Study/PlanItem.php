<?php

namespace App\Study;

/**
 * One line of an assignment's plan (App\Study\Plans): a part (a section or a deliverable), a step (a small thing
 * to do, under a part or on its own) or a criterion (what it is marked on). $weight is the marks it is worth as a
 * percentage, or null. $state is todo or done for a part or a step, not_yet, partly or met for a criterion.
 */
final readonly class PlanItem
{
    public function __construct(
        public string $id,
        public string $kind,
        public ?string $parentId,
        public string $title,
        public ?int $weight,
        public string $state,
        public int $position,
    ) {}

    public function done(): bool
    {
        return $this->state === 'done';
    }
}
