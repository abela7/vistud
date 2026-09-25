<?php

namespace App\Http\Middleware;

use App\Platform\Errors\Forbidden;
use App\Providers\FortifyServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recovery codes are shown once (ADR 0003 §10.3, M1 checklist). Fortify's
 * endpoint would show them again on request; this allows exactly one read
 * after codes are generated in the same session, then refuses.
 */
class ShowRecoveryCodesOnce
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || ! $request->routeIs('two-factor.recovery-codes')) {
            return $next($request);
        }

        if (! $request->session()->get(FortifyServiceProvider::RECOVERY_CODES_VIEWABLE, false)) {
            throw new Forbidden('recovery_codes_already_shown', 'Recovery codes are shown only once. Generate new ones if you have lost them.');
        }

        $response = $next($request);
        // Only a successful read uses up the one showing; a failed password
        // confirmation doesn't.
        if ($response->isSuccessful()) {
            $request->session()->forget(FortifyServiceProvider::RECOVERY_CODES_VIEWABLE);
        }

        return $response;
    }
}
