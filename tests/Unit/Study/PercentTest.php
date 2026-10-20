<?php

namespace Tests\Unit\Study;

use App\Study\Percent;
use PHPUnit\Framework\TestCase;

/** Progress reads 100 % only when all is done, and never 0 % once something is. */
class PercentTest extends TestCase
{
    public function test_a_percentage_is_never_full_before_the_end_or_empty_after_the_start(): void
    {
        $this->assertSame(99, Percent::of(199, 200));
        $this->assertSame(100, Percent::of(200, 200));
        $this->assertSame(1, Percent::of(1, 300));
        $this->assertSame(0, Percent::of(0, 300));
        $this->assertSame(50, Percent::of(1, 2));
        $this->assertSame(0, Percent::of(3, 0));
        $this->assertSame(67, Percent::of(0.6666, 1));
    }
}
