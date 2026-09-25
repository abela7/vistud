<?php

namespace App\Brain\Journal;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Turns an entry specification (the array the writer accepts) into a
 * JournalEntry. The writer and the tests share this, so the fixture replays
 * exactly what the database would hold.
 */
final class EntryFactory
{
    public static function make(array $spec, string $learnerId, int $position, ?DateTimeImmutable $receivedAt = null): JournalEntry
    {
        $kind = Kind::from($spec['kind']);
        $occurredAt = self::time($spec['occurred_at']);
        $until = isset($spec['occurred_until']) ? self::time($spec['occurred_until']) : null;
        $precision = Precision::from($spec['precision'] ?? 'exact');
        $tz = $spec['tz'] ?? 'UTC';
        $received = $receivedAt ?? (isset($spec['received_at']) ? self::time($spec['received_at']) : $occurredAt);

        return new JournalEntry(
            id: (string) $spec['id'],
            learnerId: $learnerId,
            position: $position,
            kind: $kind,
            type: $spec['type'] ?? 'core.'.$kind->value,
            typeVersion: (int) ($spec['type_version'] ?? 1),
            actor: Actor::fromArray($spec['actor']),
            origin: $spec['origin'] ?? null,
            occurredAt: $occurredAt,
            occurredUntil: $until,
            precision: $precision,
            tz: $tz,
            interval: Interval::compute($occurredAt, $until, $precision, $tz),
            recordedAt: isset($spec['recorded_at']) ? self::time($spec['recorded_at']) : $received,
            receivedAt: $received,
            sessionId: $spec['session'] ?? null,
            activityId: $spec['activity'] ?? null,
            links: array_values(array_map(fn (array $l) => [
                'rel' => $l['rel'],
                'target' => $l['target'],
                'role' => $l['role'] ?? null,
            ], $spec['links'] ?? [])),
            mentions: array_values($spec['mentions'] ?? []),
            body: $spec['body'] ?? [],
            contentFields: array_values(array_keys($spec['content'] ?? [])),
            captureKey: $spec['capture_key'] ?? null,
        );
    }

    public static function time(DateTimeInterface|string $value): DateTimeImmutable
    {
        $time = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable($value);

        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
