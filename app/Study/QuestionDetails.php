<?php

namespace App\Study;

/** A question the student registered: its status (the student's word) and the state the rules derive. */
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
        public ?string $moduleId = null,
        /** pending, stuck or answered: the student's word. */
        public string $status = 'pending',
        public ?string $answer = null,
        public ?string $sessionId = null,
    ) {}

    /** open or understood, as the Progress page groups them. */
    public function shown(): string
    {
        return $this->status === 'answered' ? 'understood' : 'open';
    }

    public function statusLabel(): string
    {
        return Questions::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}
