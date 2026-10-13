<?php

namespace App\Study;

use Carbon\CarbonImmutable;

/** One thing the tutor remembers about its student (App\Study\TutorMemories): a short line, for a course or for all. */
final readonly class TutorMemory
{
    public function __construct(
        public string $id,
        /** Null: for every course. */
        public ?string $workspaceId,
        public string $text,
        /** tutor or student: who wrote it. */
        public string $by,
        public ?string $sessionId,
        public ?string $topicId,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(
            (string) $row->id, $row->workspace_id === null ? null : (string) $row->workspace_id, (string) $row->text, (string) $row->by,
            $row->session_id === null ? null : (string) $row->session_id, $row->topic_id === null ? null : (string) $row->topic_id,
            (string) $row->created_at, (string) $row->updated_at,
        );
    }

    public function everywhere(): bool
    {
        return $this->workspaceId === null;
    }

    /** "The tutor, 12 Oct" or "You, 12 Oct". */
    public function source(string $zone = 'UTC'): string
    {
        return ($this->by === 'tutor' ? 'The tutor' : 'You').', '.CarbonImmutable::parse($this->updatedAt, 'UTC')->setTimezone($zone)->format('j M');
    }
}
