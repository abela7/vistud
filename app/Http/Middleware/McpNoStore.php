<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /mcp is not a page to cache (docs/specs/mcp-connections-plan.md M6.7). It sets no cookie of its own: the route
 * has no session middleware.
 */
final class McpNoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
