<?php

namespace App\Study;

/** How a student likes to learn in one course (docs/specs/vistud-2-blueprint.md §3.5.2): their answers, all optional. */
final readonly class LearnerProfileDetails
{
    /**
     * @param  list<string>  $explain  keys of LearnerProfiles::EXPLAIN
     */
    public function __construct(
        public string $workspaceId,
        public array $explain = [],
        public ?string $pace = null,
        public ?string $check = null,
        public ?string $goal = null,
        public string $note = '',
        public ?string $updatedAt = null,
    ) {}

    /** Whether the student has answered anything at all. */
    public function answered(): bool
    {
        return $this->explain !== [] || $this->pace !== null || $this->check !== null || $this->goal !== null || $this->note !== '';
    }
}
