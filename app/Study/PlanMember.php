<?php

namespace App\Study;

/** One person of an assignment's team (App\Study\Plans): a name, and whether it is the student's own. No account: only a name to give work to. */
final readonly class PlanMember
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $me,
        public int $position,
    ) {}

    /** "SB" for "Sara Bekele": for a small round badge. */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim($this->name)) ?: [];
        $letters = array_map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)), array_slice($words, 0, 2));

        return implode('', $letters) ?: '?';
    }
}
