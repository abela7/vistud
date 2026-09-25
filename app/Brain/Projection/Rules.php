<?php

namespace App\Brain\Projection;

/**
 * rules@1 thresholds (ADR 0002 §7). Provisional; replaced by a new version,
 * never edited in place, once the pilot shows they need to change.
 */
final readonly class Rules
{
    public const VERSION = 'rules@1';

    private const DAY = 86400;

    public function __construct(
        public float $retentionGap = 21 * self::DAY,
        public float $retryDelay = 1 * self::DAY,
        public int $secureTasks = 2,
        public int $secureSessions = 2,
        public int $resolveCounterTasks = 2,
        public float $reviewSecure = 30 * self::DAY,
        public float $reviewDurable = 60 * self::DAY,
        public float $dormantAfter = 21 * self::DAY,
        public int $practisedSessions = 3,
        public float $practisedSpan = 21 * self::DAY,
    ) {}
}
