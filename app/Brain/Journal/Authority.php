<?php

namespace App\Brain\Journal;

/**
 * Authority ranks for evaluations of the learner's performance (ADR 0002 §6).
 * Higher wins.
 */
final class Authority
{
    public const PERSON = 5;

    public const AUTO = 4;

    public const INTERPRETER = 3;

    public const CHAT_AI = 2;

    public const SELF = 1;

    /** Rank of a claim's method kind. Rules only accept at creation, so they rank lowest. */
    public static function ofMethod(string $kind): int
    {
        return match ($kind) {
            'person' => self::PERSON,
            'auto' => self::AUTO,
            'interpreter' => self::INTERPRETER,
            'chat_ai' => self::CHAT_AI,
            'self' => self::SELF,
            default => 0,
        };
    }

    /** Rank of an attempt's recorded outcome, or null when nobody judged it. */
    public static function ofJudgedBy(string $judgedBy): ?int
    {
        return match ($judgedBy) {
            'person' => self::PERSON,
            'auto' => self::AUTO,
            'ai' => self::CHAT_AI,
            'self' => self::SELF,
            default => null,
        };
    }

    public static function isHuman(int $rank): bool
    {
        return $rank >= self::AUTO;
    }
}
