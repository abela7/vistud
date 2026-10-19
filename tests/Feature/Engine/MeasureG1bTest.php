<?php

namespace Tests\Feature\Engine;

use App\Mcp\G1b;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** vistud:measure:g1b reports and decides nothing (docs/specs/mcp-connections-plan.md M6.7). */
class MeasureG1bTest extends TestCase
{
    use RefreshesDatabase;

    public function test_an_empty_record_is_inconclusive(): void
    {
        $this->artisan('vistud:measure:g1b')
            ->expectsOutputToContain('mcp attempts: 0')
            ->expectsOutputToContain('chat attempts: 0')
            ->expectsOutputToContain('result: inconclusive')
            ->assertSuccessful();
    }

    public function test_the_minimums_are_a_hundred_and_fifty_of_each_across_four_weeks(): void
    {
        $short = G1b::judge(['2026-01-01 00:00:00'], ['2026-01-02 00:00:00']);
        $this->assertSame('inconclusive', $short['result']);

        $mcp = array_fill(0, G1b::MIN_CASES, '2026-01-01 00:00:00');
        $chat = array_fill(0, G1b::MIN_CASES, '2026-02-15 00:00:00');
        $met = G1b::judge($mcp, $chat);
        $this->assertSame('sample met; no embedding comparison is stored, so nothing is decided', $met['result']);
        $this->assertGreaterThanOrEqual(G1b::MIN_DAYS, $met['days']);
    }
}
