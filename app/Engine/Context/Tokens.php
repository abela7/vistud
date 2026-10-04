<?php

namespace App\Engine\Context;

/**
 * How many tokens a text is, roughly: a token is about four bytes. It is an estimate for budgets, not a bill;
 * counting bytes rather than letters keeps it on the safe side for scripts that take more bytes a letter.
 */
final class Tokens
{
    public static function of(string $text): int
    {
        return intdiv(strlen($text) + 3, 4);
    }
}
