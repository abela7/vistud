<?php

namespace App\Http\Middleware;

use App\Platform\Http\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every sign-in and token attempt is logged with its request id and nothing else
 * (docs/specs/mcp-connections-plan.md M6.8). No query, no body, no code, no token.
 */
final class LogOAuthAttempt
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('oauth/authorize', 'oauth/token')) {
            Log::info('oauth.attempt', [
                'request_id' => RequestId::of($request),
                'path' => $request->path(),
            ]);
        }

        $response = $next($request);

        // A chat app whose sign-up is refused: which addresses it asked for, so the right one can be allowed
        // (config/mcp.php). Public callback addresses, never a body or a secret; long numbers (account ids) masked.
        if ($request->is('oauth/register') && $response->getStatusCode() >= 400) {
            $uris = array_slice(array_filter((array) $request->input('redirect_uris', []), 'is_string'), 0, 5);
            Log::warning('oauth.register.refused', [
                'request_id' => RequestId::of($request),
                'client_name' => mb_substr((string) $request->input('client_name', ''), 0, 60),
                'redirect_uris' => array_map(fn (string $uri) => (string) preg_replace('/\d{6,}/', '#', mb_substr(strtok($uri, '?#') ?: '', 0, 200)), $uris),
            ]);
        }

        return $response;
    }
}
