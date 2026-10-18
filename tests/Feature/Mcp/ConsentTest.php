<?php

namespace Tests\Feature\Mcp;

use App\Audit\AuditAction;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** ViStud's consent page stands between an AI app and the student's account (docs/specs/mcp-connections-plan.md §3.2). */
class ConsentTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    public function test_approving_records_the_connection_with_the_level_and_the_company(): void
    {
        $ada = $this->student();
        $tokens = $this->connectApp($ada, 'read');

        $row = DB::table('mcp_connections')->first();
        $this->assertSame($tokens['client_id'], $row->oauth_client_id);
        $this->assertSame('read', $row->level);
        $this->assertSame('anthropic', $row->provider);
        $this->assertSame('claude.ai', $row->redirect_host);
        $this->assertNull($row->revoked_at);
        $this->assertCount(1, $this->auditRows(AuditAction::CONNECTION_CONSENTED));
    }

    public function test_the_consent_page_names_the_company_and_cannot_be_framed(): void
    {
        $ada = $this->student();
        $clientId = $this->registerApp();

        $this->openConsent($ada, $clientId)
            ->assertOk()
            ->assertSee('Connect Anthropic (Claude)?')
            ->assertSee('Only approve if you just clicked Connect')
            ->assertSee('Read and write')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
    }

    public function test_a_program_on_this_computer_is_not_named_as_claude_code(): void
    {
        $ada = $this->student();
        $redirect = 'http://127.0.0.1:41234/callback';
        $clientId = $this->registerApp($redirect, 'Claude Code');

        $this->openConsent($ada, $clientId, $redirect)
            ->assertOk()
            ->assertSee('A program on this computer (for example Claude Code)');
    }

    public function test_the_consent_page_comes_back_on_a_second_sign_in(): void
    {
        $ada = $this->student();
        $this->connectApp($ada);
        $clientId = (string) DB::table('oauth_clients')->value('id');

        // A live token for the same app does not let a new sign-in skip the page.
        $this->openConsent($ada, $clientId)->assertOk()->assertSee('Only approve if you just clicked Connect');
    }

    public function test_an_approve_that_does_not_belong_to_the_page_writes_nothing(): void
    {
        $ada = $this->student();
        $clientId = $this->registerApp();
        $this->openConsent($ada, $clientId)->assertOk();

        $this->post('/connections/approve', ['auth_token' => 'forged', 'level' => 'full']);

        $this->assertSame(0, DB::table('mcp_connections')->count());
    }

    public function test_an_unknown_level_is_refused(): void
    {
        $ada = $this->student();
        $clientId = $this->registerApp();
        $page = $this->openConsent($ada, $clientId)->assertOk();

        $this->post('/connections/approve', ['auth_token' => $page->viewData('authToken'), 'level' => 'admin'])->assertStatus(422);

        $this->assertSame(0, DB::table('mcp_connections')->count());
    }

    public function test_a_valid_token_for_an_app_the_student_never_approved_here_is_refused(): void
    {
        $ada = $this->student();
        $clientId = $this->registerApp();
        Passport::actingAs($ada, ['mcp:use'], 'api', Client::query()->findOrFail($clientId));

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'me', 'arguments' => (object) []]], ['Accept' => 'application/json, text/event-stream'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'connection_not_consented');
        $this->assertCount(1, $this->auditRows(AuditAction::CONNECTION_DENIED));
    }
}
