<?php

namespace App\Brain\Journal;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The real-time interval an event occupies (ADR 0002 §3). Times are UTC epoch
 * seconds with microseconds, so gaps are plain subtraction.
 */
final readonly class Interval
{
    public function __construct(public float $lo, public float $hi) {}

    public static function compute(
        DateTimeImmutable $occurredAt,
        ?DateTimeImmutable $until,
        Precision $precision,
        string $tz,
    ): self {
        $local = $occurredAt->setTimezone(new DateTimeZone($tz));

        [$lo, $hi] = match ($precision) {
            Precision::Exact => [$occurredAt, $until ?? $occurredAt],
            Precision::Minute => [
                $local->setTime((int) $local->format('H'), (int) $local->format('i')),
                $local->setTime((int) $local->format('H'), (int) $local->format('i'))->modify('+1 minute'),
            ],
            Precision::Day => [$local->setTime(0, 0), $local->setTime(0, 0)->modify('+1 day')],
            Precision::Week => [
                $local->modify('monday this week')->setTime(0, 0),
                $local->modify('monday this week')->setTime(0, 0)->modify('+1 week'),
            ],
        };

        if ($until !== null && $precision !== Precision::Exact) {
            $hi = max($hi, $until);
        }

        return new self(self::epoch($lo), self::epoch($hi));
    }

    public static function epoch(DateTimeImmutable $time): float
    {
        return (float) $time->format('U.u');
    }

    public static function toDateTime(float $epoch): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $epoch), new DateTimeZone('UTC'));
    }

    /** Guaranteed seconds from $earlier to $later: later.lo − earlier.hi. */
    public static function guaranteedGap(self $earlier, self $later): float
    {
        return $later->lo - $earlier->hi;
    }
}
