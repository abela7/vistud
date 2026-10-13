<?php

namespace App\Study;

/** Something the student was given about an assignment, written or pasted in (App\Study\AssignmentSources). */
final readonly class AssignmentSource
{
    public function __construct(
        public string $id,
        public string $activityId,
        /** brief · rubric · email · announcement · feedback · note · correction · other */
        public string $kind,
        public ?string $title,
        public string $text,
        public ?string $receivedOn,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /** "Marking criteria", "Teacher's comment"… */
    public function kindLabel(): string
    {
        return AssignmentSources::KINDS[$this->kind] ?? 'Other';
    }

    /** Its title, or what it is when it has none. */
    public function name(): string
    {
        return $this->title ?? $this->kindLabel();
    }
}
