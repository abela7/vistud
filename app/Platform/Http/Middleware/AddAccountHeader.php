<?php

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every API response names the account it was served for (ADR 0003 §5.3).
 * A browser tab that sees an account it does not expect stops its save
 * queue at once.
 */
class AddAccountHeader
{
    public const HEADER = 'X-Account-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if ($user !== null) {
            $response->headers->set(self::HEADER, (string) $user->getAuthIdentifier());
        }

        return $response;
    }
}
