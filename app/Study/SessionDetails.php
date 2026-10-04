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
        public ?array $pomodoro = null,
        public ?string $phase = null,
        public int $phaseSeconds = 0,
        public int $phaseElapsed = 0,
        public int $pomodoros = 0,
        public int $pomodorosSkipped = 0,
        public array $tutoring = Tutoring::DEFAULTS,
        public array $material = [],
        public ?string $summary = null,
        public ?string $checkpoint = null,
        public string $mode = 'topic',
    ) {}

    /** Whether a note or file (`note:{id}`, `file:{id}`) is in the session's material. */
    public function uses(string $item): bool
    {
        return in_array($item, $this->material, true);
    }

    public function usesPomodoro(): bool
    {
        return $this->pomodoro !== null && $this->phase !== null;
    }

    /** Seconds left in the current Pomodoro phase. */
    public function phaseRemaining(): int
    {
        return max(0, $this->phaseSeconds - $this->phaseElapsed);
    }

    /** Whether the Pomodoro countdown is running now. */
    public function phaseRunning(): bool
    {
        return $this->usesPomodoro() && ($this->phase === 'focus' ? $this->state === 'running' : $this->state === 'break');
    }

    /** Waiting for the student to start the next focus period. */
    public function waitingForFocus(): bool
    {
        return $this->pausedBy === 'pomodoro';
    }

    /** "Focus 2 of 4", "Short break", "Long break", "Ready for pomodoro 3" */
    public function phaseWords(): string
    {
        if ($this->waitingForFocus()) {
            return 'Ready for pomodoro '.($this->pomodoros + 1);
        }

        return match ($this->phase) {
            'short_break' => 'Short break',
            'long_break' => 'Long break',
            default => 'Focus '.($this->pomodoros % (int) $this->pomodoro['every'] + 1).' of '.$this->pomodoro['every'],
        };
    }

    /** Names the current phase, so each phase's end is announced once, in one tab. */
    public function phaseKey(): string
    {
        return "{$this->id}:{$this->pomodoros}:{$this->pomodorosSkipped}:{$this->phase}";
    }

    /** What the end of the current phase means, for the chime's notification. */
    public function phaseEndWords(): string
    {
        return match (true) {
            $this->phase !== 'focus' => 'Break over: time to focus.',
            ($this->pomodoros + 1) % (int) $this->pomodoro['every'] === 0 => 'Pomodoro done: time for a long break.',
            default => 'Pomodoro done: time for a short break.',
        };
    }

    /** mm:ss, or h:mm:ss for an hour or more. */
    public static function countdown(int $seconds): string
    {
        return $seconds >= 3600 ? gmdate('G:i:s', $seconds) : gmdate('i:s', $seconds);
    }

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
