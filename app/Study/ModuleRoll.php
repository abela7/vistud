<?php

namespace App\Study;

/**
 * A module's topics and how far they have come (docs/specs/vistud-2-blueprint.md §3.8): understood and mastered, out of
 * all of them. The topics that are in no module make a group of their own, with no module behind it. Built by Rollups.
 */
final readonly class ModuleRoll
{
    /**
     * @param  list<TopicRoll>  $topics  in order
     */
    public function __construct(
        /** Null for the group of topics that are in no module. */
        public ?ModuleDetails $module,
        /** Its place in the course, from 1; 0 for the group with no module. */
        public int $number,
        public array $topics,
        /** Files and notes in it. */
        public int $materials = 0,
        /** The score of its latest quiz or test, 0 to 100. */
        public ?int $tested = null,
        /** Topics the reader found that wait for the student. */
        public int $suggested = 0,
        public ?string $suggestedFrom = null,
    ) {}

    public function id(): string
    {
        return $this->module->id ?? '';
    }

    public function title(): string
    {
        return $this->module->title ?? 'No module';
    }

    public function total(): int
    {
        return count($this->topics);
    }

    public function done(): int
    {
        return count(array_filter($this->topics, fn (TopicRoll $topic) => $topic->understood()));
    }

    public function percent(): int
    {
        return $this->topics === [] ? 0 : (int) round($this->done() / count($this->topics) * 100);
    }

    /** Whether any topic has been touched. */
    public function studied(): bool
    {
        return array_filter($this->topics, fn (TopicRoll $topic) => $topic->started()) !== [];
    }

    public function attention(): int
    {
        return count(array_filter($this->topics, fn (TopicRoll $topic) => $topic->needsAttention()));
    }

    /** The first topic not yet understood, in order. */
    public function next(): ?TopicRoll
    {
        foreach ($this->topics as $topic) {
            if (! $topic->understood()) {
                return $topic;
            }
        }

        return null;
    }

    public function cardsDue(): int
    {
        return array_sum(array_map(fn (TopicRoll $topic) => $topic->cardsDue, $this->topics));
    }
}
