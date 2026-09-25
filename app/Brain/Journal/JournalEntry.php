<?php

namespace App\Brain\Journal;

use DateTimeImmutable;

/**
 * One journal event as the projector sees it. Content text is deliberately
 * absent: derived state never depends on it, only on which fields exist.
 */
final readonly class JournalEntry
{
    /**
     * @param  list<array{rel: string, target: string, role?: ?string}>  $links
     * @param  list<array{n: int, field: string, start: int, end: int}>  $mentions
     * @param  list<string>  $contentFields
     */
    public function __construct(
        public string $id,
        public string $learnerId,
        public int $position,
        public Kind $kind,
        public string $type,
        public int $typeVersion,
        public Actor $actor,
        public ?string $origin,
        public DateTimeImmutable $occurredAt,
        public ?DateTimeImmutable $occurredUntil,
        public Precision $precision,
        public string $tz,
        public Interval $interval,
        public DateTimeImmutable $recordedAt,
        public DateTimeImmutable $receivedAt,
        public ?string $sessionId,
        public ?string $activityId,
        public array $links,
        public array $mentions,
        public array $body,
        public array $contentFields,
        public ?string $captureKey = null,
    ) {}

    /** Ordering key for the timeline: (lo, position). */
    public function sortKey(): array
    {
        return [$this->interval->lo, $this->position];
    }

    public function claimType(): ?string
    {
        return $this->kind === Kind::Claim ? ($this->body['type'] ?? null) : null;
    }

    /** @return list<string> */
    public function linkTargets(string $rel): array
    {
        return array_values(array_map(
            fn (array $link) => $link['target'],
            array_filter($this->links, fn (array $link) => $link['rel'] === $rel),
        ));
    }
}
