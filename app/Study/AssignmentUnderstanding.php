<?php

namespace App\Study;

/**
 * One revision of the AI's understanding of an assignment (App\Study\AssignmentUnderstandings): what it is, what to hand
 * in, what it is marked on and what the teacher wants to see, as cleaned by AssignmentUnderstandings::clean(), with what
 * changed from the revision before and the sources it cited.
 */
final readonly class AssignmentUnderstanding
{
    /**
     * @param  array<string, mixed>  $body  as AssignmentUnderstandings::clean() gives it
     * @param  array<string, array{type: string, id: string, title: string, unread?: bool}>  $sources  each label it cites
     *                                                                                                 (S1, F2): what it was; unread, a file still being
     *                                                                                                 converted when it was read
     */
    public function __construct(
        public string $id,
        public string $activityId,
        public int $revision,
        public array $body,
        public ?string $changes,
        public array $sources,
        public string $fingerprint,
        public ?string $model,
        public ?string $confirmedAt,
        public string $createdAt,
    ) {}

    /** @return list<array<string, mixed>> the items of one part of it ("criteria", "questions"…) */
    public function items(string $part): array
    {
        return is_array($this->body[$part] ?? null) ? $this->body[$part] : [];
    }

    public function text(string $part): string
    {
        return is_string($this->body[$part] ?? null) ? $this->body[$part] : '';
    }

    /** What a cited label is, for the screen ("the brief", "Rubric.pdf"); "your course" and "inferred" as such. */
    public function sourceName(?string $label): ?string
    {
        return match (true) {
            $label === null || $label === '' => null,
            $label === 'inferred' => 'the AI\'s reading',
            $label === 'course' => 'the course',
            default => $this->sources[$label]['title'] ?? null,
        };
    }

    public function confirmed(): bool
    {
        return $this->confirmedAt !== null;
    }
}
