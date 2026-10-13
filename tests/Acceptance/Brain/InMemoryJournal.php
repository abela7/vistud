<?php

namespace Tests\Acceptance\Brain;

use App\Brain\Journal\EntryFactory;
use App\Brain\Journal\EntryValidator;
use App\Brain\Journal\JournalEntry;
use App\Brain\Journal\Ref;
use App\Brain\Journal\ReviewPolicy;
use App\Brain\Projection\ProjectionOptions;
use App\Brain\Projection\Projector;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * One learner's journal held in memory. Entries go through the same contract
 * check, defaults and review policy as JournalWriter, without the database,
 * and get positions 1, 2, 3… in the order they are appended.
 */
final class InMemoryJournal
{
    /** @var array<string, JournalEntry> */
    private array $entries = [];

    public function __construct(private string $learnerId = 'L1', private string $timezone = 'Europe/London') {}

    /** @param list<array> $specs */
    public static function of(array $specs): self
    {
        $journal = new self;
        foreach ($specs as $spec) {
            $journal->append($spec);
        }

        return $journal;
    }

    /** Appends as the writer would: refuses a review that ADR 0002 §6 forbids. */
    public function append(array $spec): JournalEntry
    {
        $entry = $this->make($spec);
        $violation = $this->reviewViolation($entry);
        if ($violation !== null) {
            throw new RuntimeException("{$entry->id} refused: {$violation['message']}");
        }

        return $this->entries[$entry->id] = $entry;
    }

    /** Appends without the review check: an entry that reached the journal anyway. */
    public function force(array $spec): JournalEntry
    {
        $entry = $this->make($spec);

        return $this->entries[$entry->id] = $entry;
    }

    /** Why the writer would refuse this review, or null when it would accept it. */
    public function refusal(array $spec): ?array
    {
        return $this->reviewViolation($this->make($spec));
    }

    public function position(): int
    {
        return count($this->entries);
    }

    public function project(string $now, ?int $maxPosition = null, ?string $observedUntil = null): array
    {
        return (new Projector)->project(array_values($this->entries), new ProjectionOptions(
            new DateTimeImmutable($now),
            $maxPosition,
            $observedUntil === null ? null : new DateTimeImmutable($observedUntil),
        ));
    }

    private function make(array $spec): JournalEntry
    {
        EntryValidator::validate($spec);
        if (isset($this->entries[$spec['id']])) {
            throw new RuntimeException("Duplicate id {$spec['id']}.");
        }

        $spec['type'] ??= 'core.'.$spec['kind'];
        $spec['type_version'] ??= 1;
        $spec['precision'] ??= 'exact';
        $spec['tz'] ??= $this->timezone;
        $spec['actor']['channel'] ??= 'web';
        foreach (['occurred_at', 'occurred_until', 'recorded_at'] as $key) {
            if (isset($spec[$key])) {
                $spec[$key] = (new DateTimeImmutable($spec[$key]))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
            }
        }
        $received = EntryFactory::time($spec['recorded_at'] ?? $spec['occurred_at']);
        unset($spec['received_at']);

        return EntryFactory::make($spec, $this->learnerId, count($this->entries) + 1, $received);
    }

    private function reviewViolation(JournalEntry $entry): ?array
    {
        if ($entry->claimType() !== 'reviews' || ($entry->body['review']['state'] ?? null) !== 'accepted') {
            return null;
        }
        $target = $this->entries[Ref::id($entry->body['targets'][0])] ?? null;
        $judged = $target?->claimType() === 'judges' ? ($this->entries[Ref::id($target->body['targets'][0])] ?? null) : null;

        return ReviewPolicy::violation($entry, $target, $judged);
    }
}
