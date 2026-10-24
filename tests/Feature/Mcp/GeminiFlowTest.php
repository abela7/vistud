<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Provider;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * What can be checked here for Gemini (docs/specs/mcp-connections-plan.md M6.8). The live sign-in cannot:
 * it needs gemini.google.com and the public server. Relay hosts are not guessed.
 */
class GeminiFlowTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    public function test_a_long_state_comes_back_unchanged_and_the_attempt_is_logged_without_a_body(): void
    {
        $seen = [];
        Log::listen(function ($event) use (&$seen) {
            if ($event->message === 'oauth.attempt') {
                $seen[] = $event->context;
            }
        });
        $ada = $this->student();
        $client = $this->registerApp();
        $state = str_repeat('s', 1400);
        $this->app['auth']->shouldUse('web');
        $page = $this->actingAs($ada)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client,
            'redirect_uri' => self::CLAUDE_CALLBACK,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_pad('verifier-long-', 64, 'x'), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]))->assertOk();

        $approved = $this->post('/connections/approve', ['auth_token' => $page->viewData('authToken'), 'level' => 'read']);
        $approved->assertRedirect();
        parse_str((string) parse_url((string) $approved->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame($state, $query['state'] ?? null);

        $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client,
            'redirect_uri' => self::CLAUDE_CALLBACK,
            'code_verifier' => str_pad('verifier-long-', 64, 'x'),
            'code' => $query['code'] ?? '',
        ])->assertOk();

        $paths = array_column($seen, 'path');
        $this->assertContains('oauth/authorize', $paths);
        $this->assertContains('oauth/token', $paths);
        foreach ($seen as $context) {
            $this->assertNotSame('', $context['request_id'] ?? '');
            $this->assertArrayNotHasKey('body', $context);
        }
    }

    public function test_six_allowed_redirects_register_and_an_unknown_google_host_is_refused(): void
    {
        $paths = ['/a', '/b', '/c', '/d', '/e', '/f'];
        foreach ($paths as $path) {
            $this->postJson('/oauth/register', [
                'client_name' => 'Claude',
                'redirect_uris' => ['https://claude.ai'.$path],
            ])->assertCreated();
        }

        $this->postJson('/oauth/register', [
            'client_name' => 'Gemini',
            'redirect_uris' => ['https://unknown.googleusercontent.com/callback'],
        ])->assertStatus(400);
        $this->assertNotContains('https://unknown.googleusercontent.com', config('mcp.redirect_domains'));
    }

    public function test_geminis_own_redirect_registers_and_is_named_google_but_the_rest_of_its_host_is_not(): void
    {
        // All that Gemini sends at once, as the server's log showed them.
        $gemini = [];
        foreach (['oauth-redirect-sandbox', 'oauth-redirect-test', 'oauth-redirect'] as $host) {
            foreach (['r', 'a'] as $path) {
                $gemini[] = "https://{$host}.googleusercontent.com/{$path}/user_bound_custom-mcp-103318804661249178976-vistud_app";
            }
        }
        $this->postJson('/oauth/register', ['client_name' => 'Google', 'redirect_uris' => $gemini])->assertCreated();
        $this->assertSame(Provider::GOOGLE, Provider::fromRedirect($gemini[0]));

        $this->postJson('/oauth/register', [
            'client_name' => 'Other',
            'redirect_uris' => ['https://oauth-redirect.googleusercontent.com/elsewhere'],
        ])->assertStatus(400);
    }

    public function test_a_refused_sign_up_logs_the_addresses_it_asked_for_with_long_numbers_masked(): void
    {
        $seen = [];
        Log::listen(function ($event) use (&$seen) {
            if ($event->message === 'oauth.register.refused') {
                $seen[] = $event->context;
            }
        });

        $this->postJson('/oauth/register', [
            'client_name' => 'Gemini',
            'redirect_uris' => ['https://other.example.com/cb/user-1033188046612?code=secret'],
        ])->assertStatus(400);

        $this->assertCount(1, $seen);
        $this->assertSame('Gemini', $seen[0]['client_name']);
        $this->assertSame(['https://other.example.com/cb/user-#'], $seen[0]['redirect_uris']);
    }

    public function test_the_connections_page_tells_the_student_what_to_do_when_gemini_does_not_finish(): void
    {
        $this->actingAs($this->student())->get('/settings?part=ai')->assertOk()
            ->assertSee('Sign-in may stop before ViStud ever sees it')
            ->assertSee('Prompt for another AI');
    }
}
