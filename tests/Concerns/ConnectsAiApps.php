<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * An AI app signing in to ViStud the way Claude does (docs/specs/mcp-connections-plan.md §3.2): it registers itself,
 * sends the student to the consent page with PKCE, gets a code back once they approve, and swaps it for a token.
 */
trait ConnectsAiApps
{
    protected const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    /** @return string the new OAuth client's id */
    protected function registerApp(string $redirect = self::CLAUDE_CALLBACK, string $name = 'Claude'): string
    {
        $response = $this->postJson('/oauth/register', ['client_name' => $name, 'redirect_uris' => [$redirect]])->assertCreated();

        return (string) $response->json('client_id');
    }

    /** The consent page for this app, as the signed-in student sees it. */
    protected function openConsent(User $student, string $clientId, string $redirect = self::CLAUDE_CALLBACK, string $verifier = 'v'): TestResponse
    {
        return $this->actingAs($student)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'state-123',
            'code_challenge' => self::challenge($this->verifier($verifier)),
            'code_challenge_method' => 'S256',
        ]));
    }

    /**
     * The whole sign-in: register, consent at $level, code, token.
     *
     * @return array{client_id: string, access_token: string, refresh_token: string}
     */
    protected function connectApp(User $student, string $level = 'write', string $redirect = self::CLAUDE_CALLBACK): array
    {
        $clientId = $this->registerApp($redirect);
        $page = $this->openConsent($student, $clientId, $redirect, 'flow')->assertOk();
        $approved = $this->post('/connections/approve', ['auth_token' => $page->viewData('authToken'), 'level' => $level]);
        $approved->assertRedirect();
        parse_str((string) parse_url((string) $approved->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('state-123', $query['state'] ?? null);

        $token = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'code_verifier' => $this->verifier('flow'),
            'code' => $query['code'] ?? '',
        ])->assertOk();

        // The browser's login is not what /mcp trusts: from here on only the token counts.
        $this->app['auth']->forgetGuards();

        return ['client_id' => $clientId, 'access_token' => (string) $token->json('access_token'), 'refresh_token' => (string) $token->json('refresh_token')];
    }

    /** One JSON-RPC call to /mcp. */
    protected function mcp(?string $token, string $method, array $params = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], array_filter([
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => $token !== null ? 'Bearer '.$token : null,
        ]) + $headers);
    }

    protected function callTool(?string $token, string $name, array $arguments = [], array $headers = []): TestResponse
    {
        return $this->mcp($token, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments], $headers);
    }

    private function verifier(string $seed): string
    {
        return str_pad('verifier-'.$seed.'-', 64, 'x');
    }

    private static function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
