<?php

declare(strict_types=1);

namespace App\Study;

use Illuminate\Support\Carbon;

/**
 * One class in a module, as the screens see it. Recording, the transcript,
 * and the tutor's check are later steps on this same class.
 */
final readonly class ClassSessionDetails
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $moduleId,
        public string $kind,
        public string $title,
        public string $heldOn,
        public ?string $teacher,
        public string $status,
        public string $createdAt,
        public ?string $consentAt = null,
        public int $durationSeconds = 0,
        public ?string $noteId = null,
        public ?string $transcriptNoteId = null,
        public ?string $lectureNotesId = null,
    ) {}

    public function kindLabel(): string
    {
        return ClassSessions::KINDS[$this->kind] ?? 'Class';
    }

    /** Kind, day, and teacher, for the line under the title. */
    public function line(): string
    {
        return implode(' · ', array_filter([
            $this->kindLabel(),
            Carbon::parse($this->heldOn)->format('j M Y'),
            $this->teacher,
        ]));
    }

    public function hasConsent(): bool
    {
        return $this->consentAt !== null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            ClassSessions::NEW => 'Not recorded yet',
            ClassSessions::RECORDED => 'Recorded',
            default => 'In progress',
        };
    }
}
