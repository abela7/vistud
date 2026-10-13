<?php

namespace App\Study;

use Carbon\CarbonImmutable;

/**
 * One entry in an assignment's study log (the owner's ask, 2026-10-13): a decision taken, progress made,
 * advice given, feedback noted, or a risk spotted, kept between the student and the tutor.
 */
final readonly class AssignmentLogEntry
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $activityId,
        public string $stage,
        public string $kind,
        public string $text,
        public string $by,
        public string $createdAt,
    ) {}

    public function isByTutor(): bool
    {
        return $this->by === 'tutor';
    }

    public function isByStudent(): bool
    {
        return $this->by === 'student';
    }

    public function stageLabel(): string
    {
        return ucfirst($this->stage);
    }

    public function kindLabel(): string
    {
        return AssignmentLog::KINDS[$this->kind] ?? ucfirst($this->kind);
    }

    public function createdAgo(): string
    {
        $then = CarbonImmutable::parse($this->createdAt, 'UTC');

        return $then->diffInSeconds(CarbonImmutable::now(), true) < 60 ? 'just now' : $then->diffForHumans();
    }
}
