<?php

namespace App\Study;

use Illuminate\Support\Str;

/**
 * A study session's briefing, as any AI receives it
 * (docs/specs/study-memory.md §4.3): the tutoring prompt, then who the
 * student is and where they stand, in Markdown. $trimmed says whether
 * something was left out to keep it within the size budget.
 */
final readonly class Briefing
{
    public function __construct(
        public string $markdown,
        public string $title,
        public bool $trimmed,
    ) {}

    public function characters(): int
    {
        return mb_strlen($this->markdown);
    }

    /** Roughly how many tokens a model counts it as (about four characters each). */
    public function tokens(): int
    {
        return (int) ceil($this->characters() / 4);
    }

    /** "vistud-briefing-joins-2026-10-05.md" */
    public function filename(string $date): string
    {
        return 'vistud-briefing-'.(Str::slug($this->title) ?: 'session').'-'.$date.'.md';
    }
}
