<?php

namespace App\Http\Middleware;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\TwoFactorRequired;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `two_factor`. An admin without confirmed 2FA is sent to enrol (browser)
 * or gets 403 two_factor_required (JSON), for every admin route, including
 * direct entry by URL (ADR 0003 §10.3).
 */
class EnsureTwoFactorEnrolled
{
    public function __construct(private PrincipalFactory $principals) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->principals->fromRequest($request)->twoFactorConfirmed) {
            throw new TwoFactorRequired;
        }

        return $next($request);
    }
}
