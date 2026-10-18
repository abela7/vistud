<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * While ViStud is reached through a tunnel for a test (TUNNEL=1; docs/specs/mcp-connections-plan.md M6.1), only what
 * an AI app's sign-in and calls need answers: /mcp, the OAuth paths, login, 2FA and the consent step. Everything else
 * is 404, so a tunnel URL shared by accident shows nothing. It refuses to run with APP_DEBUG on, whose error pages
 * would print secrets to anyone holding the URL.
 */
final class McpTunnelOnly
{
    /** @var list<string> */
    private const OPEN = [
        'mcp',
        '.well-known/*',
        'oauth/*',
        'login',
        'logout',
        'two-factor-challenge',
        'connections/approve',
        'livewire/*',
        'build/*',
        'favicon*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mcp.tunnel', false)) {
            return $next($request);
        }
        if (config('app.debug')) {
            throw new RuntimeException('TUNNEL=1 needs APP_DEBUG=false.');
        }
        if (! $request->is(...self::OPEN)) {
            abort(404);
        }

        return $next($request);
    }
}
