<?php

namespace App\Study;

use Carbon\CarbonImmutable;

/**
 * One topic on the Progress tree (docs/specs/vistud-2-blueprint.md §3.5.5, §3.8): where it stands, what hangs on it
 * (cards, questions, sessions, how it did in a test) and why it needs another look, if it does. Built by Rollups.
 */
final readonly class TopicRoll
{
    /** Why a topic needs attention, most urgent first. */
    public const REASONS = ['confusing', 'stuck', 'test', 'overdue'];

    /**
     * @param  list<string>  $reasons  what needs attention (REASONS), most urgent first; empty when nothing does
     */
    public function __construct(
        public TopicDetails $topic,
        /** not_started, covered, understood, confused or mastered. */
        public string $shown,
        public int $cards = 0,
        public int $cardsDue = 0,
        public int $cardsOverdue = 0,
        public int $openQuestions = 0,
        public int $stuckQuestions = 0,
        public int $sessions = 0,
        /** How it did in the latest test it was in, 0 to 100. */
        public ?int $tested = null,
        public array $reasons = [],
        /** The day (Y-m-d) the status the topic shows was set. */
        public ?string $since = null,
    ) {}

    public function id(): string
    {
        return $this->topic->id;
    }

    public function understood(): bool
    {
        return in_array($this->shown, ['understood', 'mastered'], true);
    }

    public function started(): bool
    {
        return $this->shown !== 'not_started';
    }

    public function needsAttention(): bool
    {
        return $this->reasons !== [];
    }

    /** The word beside the topic: an icon's one word. */
    public function word(): string
    {
        return ['not_started' => 'Not started', 'covered' => 'Covered', 'understood' => 'Understood', 'confused' => 'Still confusing', 'mastered' => 'Mastered'][$this->shown] ?? ucfirst($this->shown);
    }

    /** "since 2 Oct · 2 cards": the small print under the word. */
    public function small(): string
    {
        $parts = [];
        if ($this->shown === 'confused' && $this->since !== null) {
            $parts[] = 'since '.CarbonImmutable::parse($this->since)->format('j M');
        }
        if ($this->cards > 0) {
            $parts[] = ($this->cards === 1 ? '1 card' : "{$this->cards} cards").($this->cardsDue > 0 ? ", {$this->cardsDue} due" : '');
        }
        if ($this->sessions > 0) {
            $parts[] = $this->sessions === 1 ? '1 session' : "{$this->sessions} sessions";
        }

        return implode(' · ', $parts);
    }

    /** What needs another look, in a few words: "a stuck question · 3 cards a week late". */
    public function attentionWords(): string
    {
        $words = [];
        foreach ($this->reasons as $reason) {
            $words[] = match ($reason) {
                'confusing' => 'confusing for over a week',
                'stuck' => $this->stuckQuestions === 1 ? 'a stuck question' : "{$this->stuckQuestions} stuck questions",
                'test' => "{$this->tested} % in the test",
                'overdue' => ($this->cardsOverdue === 1 ? '1 card' : "{$this->cardsOverdue} cards").' a week late',
                default => $reason,
            };
        }

        return implode(' · ', $words);
    }

    /** What the status stands on, for a tooltip: "Seen, not practised · not practised yet · set by the tutor, 5 Oct". */
    public function evidence(): string
    {
        $parts = [ucfirst($this->topic->evidence())];
        if ($this->topic->byTutor() && $this->topic->statusAt !== null) {
            $parts[] = 'set by the tutor, '.CarbonImmutable::parse($this->topic->statusAt, 'UTC')->format('j M');
        }

        return implode(' · ', $parts);
    }
}
