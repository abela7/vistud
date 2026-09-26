<?php

namespace App\Study;

/**
 * A study session, as the screens see it. $studySeconds and $breakSeconds
 * include the open segment up to the moment it was read; $openKind and
 * $openSince say what the clock is counting now (null when nothing is).
 * $segments is filled only when one session is opened.
 */
final readonly class SessionDetails
{
    /** @param list<SessionSegment> $segments */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $moduleId,
        public ?string $topicId,
        public string $state,
        public ?string $pausedBy,
        public bool $manual,
        public string $startedAt,
        public ?string $endedAt,
        public string $lastActivityAt,
        public int $studySeconds,
        public int $breakSeconds,
        public ?string $openKind,
        public ?string $openSince,
        public array $segments = [],
    ) {}

    public function isOpen(): bool
    {
        return $this->state !== 'ended';
    }

    /** "1 h 25 min", "25 min", "under a minute" */
    public static function duration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);

        return match (true) {
            $minutes === 0 => $seconds === 0 ? '0 min' : 'under a minute',
            $minutes < 60 => "{$minutes} min",
            $minutes % 60 === 0 => intdiv($minutes, 60).' h',
            default => intdiv($minutes, 60).' h '.($minutes % 60).' min',
        };
    }

    /** "Running", "Paused", "On a break", "Ended" */
    public function stateWords(): string
    {
        return ['running' => 'Studying', 'paused' => 'Paused', 'break' => 'On a break', 'ended' => 'Ended'][$this->state] ?? $this->state;
    }
}
