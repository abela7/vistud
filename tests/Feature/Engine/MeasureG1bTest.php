<?php

namespace Tests\Feature\Engine;

use App\Mcp\G1b;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** vistud:measure:g1b reports and decides nothing (docs/specs/mcp-connections-plan.md M6.7). */
class MeasureG1bTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_encrypted_bodies_are_decrypted_in_php_before_counting_the_judge_via(): void
    {
        $by = $this->principal($this->student());
        app(Workspaces::class)->create($by, ['name' => 'Course']);
        $scope = LearnerScope::of($by);
        $template = (array) LearnerTables::query($scope, 'journal_entries')->first();
        foreach (['mcp', 'chat', 'other'] as $index => $via) {
            $row = $template;
            $row['id'] = 'measure-'.$index;
            $row['position'] = 100 + $index;
            $row['capture_key'] = null;
            $row['body'] = json_encode(['judge' => ['via' => $via], 'text' => 'synthetic marker']);
            LearnerTables::insert($scope, 'journal_entries', $row);
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->artisan('vistud:measure:g1b')->expectsOutputToContain('mcp attempts: 1')
            ->expectsOutputToContain('chat attempts: 1')->assertSuccessful();
        $this->assertStringNotContainsString('JSON_EXTRACT', implode(' ', $queries));
        $this->assertStringNotContainsString('synthetic marker', DB::table('journal_entries')->where('id', 'measure-0')->value('body'));
    }

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
