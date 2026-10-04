<?php

namespace App\Study;

use Carbon\CarbonImmutable;

/**
 * A flashcard, as the screens see it. $dueOn is the student's date it's next
 * due (Y-m-d), or null for a new card, which is due now.
 */
final readonly class FlashcardDetails
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $topicId,
        public string $front,
        public string $back,
        public string $author,
        public ?string $sessionId,
        public int $revision,
        public int $step,
        public ?string $dueOn,
        public int $reviews,
        public int $lapses,
        public ?string $lastResult,
        public string $createdAt,
        /** The module the card belongs to: its topic's, or the one it was made in; null for the course as a whole. */
        public ?string $moduleId = null,
    ) {}

    public function isNew(): bool
    {
        return $this->dueOn === null;
    }

    /** Due on or before $today (the student's date, Y-m-d); a new card always is. */
    public function due(string $today): bool
    {
        return $this->dueOn === null || $this->dueOn <= $today;
    }

    /** "New", "Due now", "Due tomorrow", "Due in 3 days", "Due 12 Oct". */
    public function dueWords(string $today): string
    {
        if ($this->dueOn === null) {
            return 'New';
        }
        if ($this->dueOn <= $today) {
            return 'Due now';
        }
        $from = CarbonImmutable::parse($today);
        $due = CarbonImmutable::parse($this->dueOn);
        $days = (int) $from->diffInDays($due);

        return match (true) {
            $days === 1 => 'Due tomorrow',
            $days < 7 => "Due in {$days} days",
            $due->year === $from->year => 'Due '.$due->format('j M'),
            default => 'Due '.$due->format('j M Y'),
        };
    }
}
