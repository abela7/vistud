<?php

namespace App\Study;

/** A stretch of a session: study or a break, and how it ended (pause, break, resume, end, away, long_break, log; null while open). */
final readonly class SessionSegment
{
    public function __construct(
        public string $kind,
        public string $startedAt,
        public ?string $endedAt,
        public ?string $endedBy,
    ) {}
}
