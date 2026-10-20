<?php

namespace App\Study;

/**
 * A progress percentage as a student reads it: 100 only when all of it is done, and never 0 once some of it is. Plain
 * rounding said 100 % with one topic of 200 still to go, and 0 % with one of 300 done.
 */
final class Percent
{
    public static function of(int|float $part, int|float $whole): int
    {
        if ($whole <= 0 || $part <= 0) {
            return 0;
        }
        if ($part >= $whole) {
            return 100;
        }

        return max(1, min(99, (int) round($part / $whole * 100)));
    }
}
