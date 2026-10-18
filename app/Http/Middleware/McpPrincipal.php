<?php

namespace App\Http\Middleware;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Identity\PrincipalFactory;
use App\Mcp\Connections;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerKeys;
use App\Platform\Errors\AccountDeleted;
use App\Platform\Errors\Forbidden;
use App\Platform\Errors\Unavailable;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who is calling /mcp (docs/specs/mcp-connections-plan.md §3.2), after `auth:api` has checked the token. It answers:
 *
 * - 503 while the kill switch (config('mcp.enabled')) is off;
 * - 403 for a foreign Origin header (an absent one is fine: the chat apps call server to server);
 * - 403 with no sign-in challenge for a suspended or deleted account, so a chat app does not loop on sign-in;
 * - 403 when the token's app has no live connection: the student never approved it here, or cut it off (fail closed).
 *
 * Otherwise the request carries the student's Principal (Student role only) and its connection row.
 */
final class McpPrincipal
{
    public const CONNECTION = 'vistud.mcp.connection';

    public function __construct(private PrincipalFactory $principals, private AuditLog $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mcp.enabled', true)) {
            throw new Unavailable('mcp_disabled', 'AI connections are switched off for now.');
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null && $origin !== '' && ! self::sameHost($origin)) {
            throw new Forbidden('origin_not_allowed', 'This request came from a page that may not call ViStud.');
        }

        try {
            $principal = $this->principals->forToken($request);
        } catch (AccountDeleted) {
            throw new Forbidden('account_deleted', 'This ViStud account no longer exists.');
        }

        $scope = Guard::learner($principal);
        $token = $request->user('api')?->token();
        $clientId = $token instanceof AccessToken ? (string) $token->oauth_client_id : '';
        $connection = $clientId === '' ? null : Connections::live($scope, $clientId);
        if ($connection === null) {
            $this->audit->record($principal, AuditAction::CONNECTION_DENIED, 'oauth_client', $clientId !== '' ? $clientId : null, ['reason' => 'consent']);
            throw new Forbidden('connection_not_consented', 'Connect this app again from its own settings.');
        }

        Connections::touch($scope, $connection);
        LearnerKeys::unlock($principal);
        $request->attributes->set(self::CONNECTION, $connection);

        return $next($request);
    }

    private static function sameHost(string $origin): bool
    {
        $mine = parse_url((string) config('app.url'), PHP_URL_HOST);
        $theirs = parse_url($origin, PHP_URL_HOST);

        return is_string($mine) && is_string($theirs) && strcasecmp($mine, $theirs) === 0;
    }
}
