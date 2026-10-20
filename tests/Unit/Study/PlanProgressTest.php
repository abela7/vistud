<?php

namespace Tests\Unit\Study;

use App\Study\PlanProgress;
use PHPUnit\Framework\TestCase;

/** What the tutor is told about a plan's progress never reads as unfinished once everything is ticked. */
class PlanProgressTest extends TestCase
{
    public function test_progress_words_say_when_everything_is_done_whatever_the_weights_add_up_to(): void
    {
        $this->assertSame('2 of 5 done, 35%', (new PlanProgress(2, 5, 35))->words());
        $this->assertSame('all 5 done', (new PlanProgress(5, 5, 100))->words());
        // The sections' weights add up to 60: the percentage stays at 60, and the words say why.
        $this->assertSame("all 2 done (60%: the sections' weights add up to less than 100)", (new PlanProgress(2, 2, 60, weighted: true))->words());
        $this->assertSame('0 of 3 done, 0%', (new PlanProgress(0, 3, 0))->words());
    }
}
