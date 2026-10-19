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

        return $next($request);
    }
}
