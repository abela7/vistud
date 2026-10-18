<?php

namespace Tests\Feature\Mcp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * MCP is the only thing Passport tokens open (docs/specs/mcp-connections-plan.md §3.2): /api/v1 and /admin run on the
 * web session and never list the `api` guard, and connections unused for months are cut off by the nightly upkeep.
 */
class TokenKindsTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    public function test_only_mcp_takes_a_passport_token(): void
    {
        $open = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $middleware = Route::gatherRouteMiddleware($route);
            $usesApiGuard = collect($middleware)->contains(fn ($m) => is_string($m) && str_contains($m, 'Authenticate:api'));
            if ($usesApiGuard) {
                $open[] = $route->uri();
            }
        }

        $this->assertSame(['mcp'], $open);
    }

    public function test_a_connection_unused_for_months_is_cut_off_at_night_and_old_calls_go(): void
    {
        $ada = $this->student();
        $tokens = $this->connectApp($ada);
        $connection = DB::table('mcp_connections')->first();
        // elapsed: the test moves real time past the retention windows.
        DB::table('mcp_connections')->update(['last_used_at' => now()->subDays(91)]);
        DB::table('mcp_calls')->insert([
            ['id' => (string) str()->uuid(), 'learner_id' => $connection->learner_id, 'connection_id' => $connection->id, 'tool' => 'me', 'outcome' => 'ok', 'chars' => 0, 'created_at' => now()->subDays(401)],
            ['id' => (string) str()->uuid(), 'learner_id' => $connection->learner_id, 'connection_id' => $connection->id, 'tool' => 'me', 'outcome' => 'ok', 'chars' => 0, 'created_at' => now()->subDays(2)],
        ]);

        $this->artisan('vistud:mcp:prune')->expectsOutput('Cut 1 unused connection, deleted 1 old call record.')->assertSuccessful();

        $this->assertSame('unused', DB::table('mcp_connections')->value('revoked_reason'));
        $this->assertSame(1, DB::table('mcp_calls')->count());
        $this->callTool($tokens['access_token'], 'me')->assertStatus(401);
    }

    public function test_a_connection_used_recently_stays(): void
    {
        $this->connectApp($this->student());
        DB::table('mcp_connections')->update(['last_used_at' => now()->subDays(10)]);

        $this->artisan('vistud:mcp:prune')->expectsOutput('Cut 0 unused connections, deleted 0 old call records.')->assertSuccessful();

        $this->assertNull(DB::table('mcp_connections')->value('revoked_at'));
    }
}
