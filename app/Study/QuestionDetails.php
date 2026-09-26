<?php

namespace App\Study;

/** A question the student registered, with the state the rules derive for it. */
final readonly class QuestionDetails
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $topicId,
        public string $text,
        public bool $askTeacher,
        public string $state,
        public array $flags,
        public string $askedAt,
    ) {}

    /** open or understood: the student's view of the rules' states. */
    public function shown(): string
    {
        return str_starts_with($this->state, 'resolved') ? 'understood' : 'open';
    }
}
