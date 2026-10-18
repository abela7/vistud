<?php

namespace Tests\Feature\Mcp;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** How an AI app finds ViStud's sign-in and registers itself (docs/specs/mcp-connections-plan.md §3.2, M6.1). */
class OAuthDiscoveryTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    public function test_both_discovery_documents_point_at_this_server(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp')
            ->assertOk()
            ->assertJsonPath('resource', url('/mcp'))
            ->assertJsonPath('scopes_supported', ['mcp:use']);

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('registration_endpoint', url('/oauth/register'))
            ->assertJsonPath('token_endpoint', url('/oauth/token'));
    }

    public function test_only_the_known_chat_apps_and_this_computer_may_register(): void
    {
        $this->registerApp('https://claude.ai/api/mcp/auth_callback');
        $this->registerApp('https://chatgpt.com/connector/oauth/abc');
        $this->registerApp('http://localhost:33418/callback', 'Claude Code');

        $this->postJson('/oauth/register', ['client_name' => 'Evil', 'redirect_uris' => ['https://evil.example/callback']])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');
        // A look-alike host is not claude.ai.
        $this->postJson('/oauth/register', ['client_name' => 'Evil', 'redirect_uris' => ['https://claude.ai.evil.example/callback']])
            ->assertStatus(400);

        $this->assertSame(3, DB::table('oauth_clients')->count());
    }

    public function test_registration_is_throttled_per_address(): void
    {
        config(['mcp.register_per_minute' => 2]);
        $this->registerApp();
        $this->registerApp();
        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::CLAUDE_CALLBACK]])->assertStatus(429);
    }

    public function test_a_student_with_a_uuid_signs_an_app_in_and_it_can_ask_who_they_are(): void
    {
        $ada = $this->student(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
        $tokens = $this->connectApp($ada);

        $this->assertNotSame('', $tokens['access_token']);
        $this->assertSame($ada->id, DB::table('oauth_access_tokens')->value('user_id'));

        $answer = $this->callTool($tokens['access_token'], 'me')->assertOk();
        $text = (string) $answer->json('result.content.0.text');
        $this->assertStringContainsString('Ada', $text);
        $this->assertStringContainsString('a••••@example.test', $text);
        $this->assertStringNotContainsString('Lovelace', $text);
        $this->assertStringNotContainsString('ada@example.test', $text);
    }

    public function test_the_tools_list_has_me_marked_read_only(): void
    {
        $tokens = $this->connectApp($this->student());

        $tools = $this->mcp($tokens['access_token'], 'tools/list')->assertOk()->json('result.tools');
        $me = collect($tools)->firstWhere('name', 'me');
        $this->assertNotNull($me);
        $this->assertTrue($me['annotations']['readOnlyHint']);
        $this->assertFalse($me['annotations']['destructiveHint']);
    }
}
