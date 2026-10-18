<?php

namespace Tests\Feature\Mcp;

use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Through a test tunnel, only the AI-app paths answer (docs/specs/mcp-connections-plan.md M6.1). */
class TunnelGuardTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_with_the_tunnel_on_only_sign_in_and_mcp_answer(): void
    {
        config(['mcp.tunnel' => true, 'app.debug' => false]);

        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk();
        $this->get('/login')->assertOk();
        $this->postJson('/mcp', [])->assertStatus(401);

        $this->actingAs($this->student());
        $this->get('/settings')->assertNotFound();
        $this->get('/')->assertNotFound();
    }

    public function test_the_tunnel_refuses_to_run_with_debug_on(): void
    {
        config(['mcp.tunnel' => true, 'app.debug' => true]);

        $this->get('/login')->assertStatus(500);
    }

    public function test_without_the_tunnel_nothing_changes(): void
    {
        config(['mcp.tunnel' => false]);
        $this->actingAs($this->student());

        $this->get('/settings')->assertOk();
    }
}
