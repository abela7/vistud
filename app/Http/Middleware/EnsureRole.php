<?php

namespace App\Http\Middleware;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Role;
use App\Platform\Errors\Forbidden;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `role:admin`. A user without the role gets 403, and the attempt is
 * audited (ADR 0003 D6). Services check again; this is the fast path.
 */
class EnsureRole
{
    public function __construct(private PrincipalFactory $principals, private AuditLog $audit) {}

    public function handle(Request $request, Closure $next, string $role): Response
    {
        $principal = $this->principals->fromRequest($request);

        if (! $principal->hasRole(Role::from($role))) {
            $this->audit->record($principal, AuditAction::ADMIN_ACCESS_DENIED, 'route', self::routeName($request));
            throw new Forbidden($role.'_role_required');
        }

        return $next($request);
    }

    private static function routeName(Request $request): ?string
    {
        $name = $request->route()?->getName();

        return is_string($name) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $name) === 1 ? $name : null;
    }
}
