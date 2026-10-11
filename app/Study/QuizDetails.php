<?php

namespace App\Study;

use Carbon\CarbonImmutable;

/**
 * A quiz or a test, as the screens see it (docs/specs/vistud-2-blueprint.md §3.9): what was asked and how each answer
 * went, the score, and, for a test, the status each topic it touched would earn.
 */
final readonly class QuizDetails
{
    /**
     * @param  list<array{asked: string, answer: string, result: string, right: string, fix: string, topic: ?string, topic_id: ?string}>  $questions
     * @param  list<array{topic_id: string, topic: string, score: int, status: string}>  $proposals  by topic, for a test; empty for a quiz
     */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $sessionId,
        public ?string $moduleId,
        public ?string $topicId,
        public string $kind,
        public array $questions,
        public int $score,
        public int $asked,
        public string $startedAt,
        public string $finishedAt,
        public array $proposals = [],
    ) {}

    /** How many answers were right: a correct one counts, a partial one does not. */
    public function right(): int
    {
        return count(array_filter($this->questions, fn (array $q) => $q['result'] === 'correct'));
    }

    /** Duration of the quiz in seconds. */
    public function durationSeconds(): int
    {
        $start = CarbonImmutable::parse($this->startedAt);
        $end = CarbonImmutable::parse($this->finishedAt);

        return max(1, (int) abs($end->diffInSeconds($start)));
    }

    /** Human-readable duration (e.g. "45s", "2m 15s"). */
    public function durationWords(): string
    {
        $secs = $this->durationSeconds();
        if ($secs < 60) {
            return "{$secs}s";
        }
        $mins = (int) floor($secs / 60);
        $rem = $secs % 60;

        return $rem > 0 ? "{$mins}m {$rem}s" : "{$mins}m";
    }

    /** Average pace per question (e.g. "27s / question"). */
    public function paceWords(): string
    {
        if ($this->asked <= 0) {
            return '';
        }
        $avg = (int) round($this->durationSeconds() / $this->asked);

        return "{$avg}s / question";
    }
}
