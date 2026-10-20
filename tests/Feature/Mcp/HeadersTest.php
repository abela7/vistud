<?php

namespace Tests\Feature\Mcp;

use App\Study\Modules;
use App\Study\Workspaces;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** What /mcp and the sign-in pages send (docs/specs/mcp-connections-plan.md M6.7). */
class HeadersTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    public function test_mcp_sets_no_cookie_and_is_not_stored(): void
    {
        $token = $this->connectApp($this->student(), 'read')['access_token'];
        $response = $this->callTool($token, 'me')->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('Set-Cookie'));
    }

    public function test_a_missing_token_still_says_where_to_sign_in(): void
    {
        $response = $this->callTool(null, 'me')->assertStatus(401);

        $this->assertStringContainsString('Bearer', (string) $response->headers->get('WWW-Authenticate'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_consent_and_confirm_pages_cannot_be_framed_and_set_no_form_action(): void
    {
        $ada = $this->student();
        $client = $this->registerApp();
        $consent = $this->openConsent($ada, $client)->assertOk();
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $consent->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('form-action', (string) $consent->headers->get('Content-Security-Policy'));

        $by = $this->principal($ada);
        $course = app(Workspaces::class)->create($by, ['name' => 'Databases'])->id;
        $module = app(Modules::class)->create($by, $course, ['title' => 'Week 2'])->id;
        $token = $this->connectApp($ada, 'full')['access_token'];
        $asked = $this->callTool($token, 'rename_module', ['module_id' => $module, 'new_title' => 'Scheduling'])->assertOk();
        preg_match('#/connections/confirm/[A-Za-z0-9-]+#', (string) $asked->json('result.content.0.text'), $match);
        $page = $this->actingAs($ada)->get($match[0])->assertOk();
        $policy = (string) $page->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringNotContainsString('form-action', $policy);
        $this->assertSame('DENY', $page->headers->get('X-Frame-Options'));
        $this->assertSame('Week 2', app(Modules::class)->find($by, $module)->title);
    }

    public function test_the_tunnel_guard_hides_the_rest_of_the_app(): void
    {
        config(['mcp.tunnel' => true, 'app.debug' => false]);

        $this->get('/login')->assertOk();
        $this->get('/settings')->assertNotFound();
    }
}
