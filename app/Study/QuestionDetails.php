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
        /** The folder it is in (docs/specs/vistud-2-blueprint.md, Phase 9), or null. */
        public ?string $folderId = null,
        /** Who said it is answered: student, or ai while the student hasn't said it clicked; null when it isn't. */
        public ?string $answeredBy = null,
    ) {}

    /** open or understood, as the Progress page groups them: an answer the AI wrote isn't understanding yet. */
    public function shown(): string
    {
        return $this->status === 'answered' && ! $this->answeredByAi() ? 'understood' : 'open';
    }

    /**
     * Still to be settled, as the Progress page shows it: not answered, or answered by the AI and not yet confirmed
     * by the student. The tutor's tools and briefs count open questions the same way, so they never say "no open
     * questions" while the screen shows one.
     */
    public function open(): bool
    {
        return $this->status !== 'answered' || $this->answeredByAi();
    }

    /** Answered by the AI, and the student hasn't said yet that it clicked. */
    public function answeredByAi(): bool
    {
        return $this->status === 'answered' && $this->answeredBy === 'ai';
    }

    public function statusLabel(): string
    {
        return Questions::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}
