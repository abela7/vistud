<?php

namespace App\Http\Middleware;

use App\Identity\PrincipalFactory;
use App\Models\User;
use App\Platform\Errors\AccountDeleted;
use App\Platform\Errors\AppError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        if (! $user instanceof User && self::namesErasedAccount($request)) {
            throw new AccountDeleted;
        }
        if ($user instanceof User && ! $user->fresh()->hasVerifiedEmail()) {
            $status = $user->fresh()->status;
            if ($status === 'active') {
                if ($request->routeIs('verification.*', 'logout', 'privacy', 'icons')) {
                    return $next($request);
                }

                return $request->expectsJson()
                    ? response()->json(['error' => ['code' => 'email_unverified', 'message' => 'Confirm your email address first.']], 403)
                    : redirect()->route('verification.notice');
            }
        }
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

    /**
     * A draft save from a browser whose login is gone. The editor sends the account the page was served for.
     * The session may still name that account too. Either one, once it is in the erasure record, is account_deleted
     * so the editor drops that account's drafts.
     */
    /** The editor's account id, or the session's login, once that account is in the erasure record. */
    public static function namesErasedAccount(Request $request): bool
    {
        $ids = [];
        $header = $request->headers->get('X-Account-Id');
        if (is_string($header) && preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $header) === 1) {
            $ids[] = $header;
        }
        if ($request->hasSession()) {
            $sessionUser = $request->session()->get(Auth::guard('web')->getName());
            if (is_string($sessionUser) && $sessionUser !== '') {
                $ids[] = $sessionUser;
            }
        }
        if ($ids === []) {
            return false;
        }

        return DB::table('erasure_log')->whereIn('user_id', array_values(array_unique($ids)))->exists();
    }
}
