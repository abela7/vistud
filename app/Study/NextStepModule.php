<?php

namespace App\Study;

/** One module as the Next rule sees it (docs/specs/vistud-2-blueprint.md §3.3): plain counts, so the rule stays pure. */
final readonly class NextStepModule
{
    public function __construct(
        public string $id,
        public int $number,
        public string $title,
        public string $url,
        /** Files and notes in it. */
        public int $materials = 0,
        public int $topics = 0,
        /** Topics the student understands (or has mastered). */
        public int $understood = 0,
        /** Whether any of its topics has been touched. */
        public bool $studied = false,
        /** Topics the reader found that the student hasn't added or dismissed. */
        public int $suggested = 0,
        public ?string $suggestedFrom = null,
        /** The first topic not yet understood, in order. */
        public ?string $nextTopicId = null,
        public ?string $nextTopicName = null,
    ) {}

    public function done(): bool
    {
        return $this->topics > 0 && $this->understood >= $this->topics;
    }

    public function left(): int
    {
        return max(0, $this->topics - $this->understood);
    }
}
