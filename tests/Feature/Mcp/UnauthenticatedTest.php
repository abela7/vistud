<?php

namespace Tests\Feature\Mcp;

use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** What /mcp answers without a valid token, and what a token cannot reach (docs/specs/mcp-connections-plan.md §3.2). */
class UnauthenticatedTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    public function test_no_token_is_a_401_that_tells_the_app_where_to_sign_in(): void
    {
        $response = $this->callTool(null, 'me')->assertStatus(401);

        $challenge = (string) $response->headers->get('WWW-Authenticate');
        $this->assertStringContainsString('resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"', $challenge);
    }

    public function test_a_browser_login_alone_does_not_open_mcp(): void
    {
        $this->actingAs($this->student());

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream'])
            ->assertStatus(401);
    }

    public function test_a_made_up_token_is_a_401(): void
    {
        $this->callTool('not-a-token', 'me')->assertStatus(401);
    }

    public function test_a_page_on_another_site_cannot_call_with_a_token(): void
    {
        $tokens = $this->connectApp($this->student());

        $this->callTool($tokens['access_token'], 'me', [], ['Origin' => 'https://evil.example'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'origin_not_allowed');
        // The chat apps call server to server, without an Origin.
        $this->callTool($tokens['access_token'], 'me')->assertOk();
    }

    public function test_an_mcp_token_does_not_open_the_json_api(): void
    {
        $tokens = $this->connectApp($this->student());

        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertStatus(401);
    }

    public function test_the_kill_switch_answers_503(): void
    {
        $tokens = $this->connectApp($this->student());
        config(['mcp.enabled' => false]);

        $this->callTool($tokens['access_token'], 'me')->assertStatus(503)->assertJsonPath('error.code', 'mcp_disabled');
    }
}
