<?php

namespace App\Http\Middleware;

use App\Identity\PrincipalFactory;
use App\Models\User;
use App\Platform\Errors\AppError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every web and API request. A suspended or deleted account is
 * logged out at once, on its next request: 403 access_revoked for suspended,
 * 401 account_deleted for deleted (ADR 0003 §5.3).
 */
class EnsureAccountActive
{
    public function __construct(private PrincipalFactory $principals) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            try {
                $this->principals->fromRequest($request);
            } catch (AppError $e) {
                Auth::guard('web')->logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
                throw $e;
            }
        }

        return $next($request);
    }
}
