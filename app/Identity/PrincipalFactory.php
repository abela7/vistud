<?php

namespace App\Identity;

use App\Models\User;
use App\Platform\Access\Area;
use App\Platform\Access\Principal;
use App\Platform\Access\Role;
use App\Platform\Errors\AccessRevoked;
use App\Platform\Errors\AccountDeleted;
use App\Platform\Http\RequestId;
use DateTimeImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Builds the Principal that adapters pass to services (docs/architecture/contracts.md).
 * This is the only place that reads the login, the session and the request
 * on behalf of services.
 */
final class PrincipalFactory
{
    /** Session key for the active area. */
    public const AREA_KEY = 'vistud.area';

    /** Laravel's password-confirmation timestamp, also written by Fortify. */
    public const PASSWORD_CONFIRMED_KEY = 'auth.password_confirmed_at';

    private const ATTRIBUTE = 'vistud.principal';

    public function __construct(private RoleStore $roles) {}

    public function fromRequest(Request $request): Principal
    {
        $cached = $request->attributes->get(self::ATTRIBUTE);
        if ($cached instanceof Principal) {
            return $cached;
        }

        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $session = $request->hasSession() ? $request->session() : null;
        $confirmedAt = $session?->get(self::PASSWORD_CONFIRMED_KEY);
        $area = Area::tryFrom((string) $session?->get(self::AREA_KEY));

        $principal = $this->forUser(
            $user,
            channel: $request->is('api/*') ? 'api' : 'web',
            passwordConfirmedAt: is_numeric($confirmedAt) ? new DateTimeImmutable('@'.(int) $confirmedAt) : null,
            area: $area,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            requestId: RequestId::of($request),
        );
        $request->attributes->set(self::ATTRIBUTE, $principal);

        return $principal;
    }

    /** Drops the cached principal, after something it depends on changed during the request. */
    public function forget(Request $request): void
    {
        $request->attributes->remove(self::ATTRIBUTE);
    }

    /**
     * For adapters that act as a known user outside a request, such as a
     * console command run on a user's behalf, and for tests.
     */
    public function forUser(
        User $user,
        string $channel,
        ?DateTimeImmutable $passwordConfirmedAt = null,
        ?Area $area = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $requestId = null,
    ): Principal {
        // Status and 2FA come from the database, not the in-memory model, so
        // a suspension or a 2FA reset takes effect on the very next request.
        $state = DB::table('users')->where('id', $user->id)->first(['status', 'two_factor_secret', 'two_factor_confirmed_at']);
        self::assertStatus($state?->status);

        $roles = $this->roles->rolesOf($user->id);

        return new Principal(
            userId: $user->id,
            learnerId: in_array(Role::Student, $roles, true) ? $this->roles->learnerIdOf($user->id) : null,
            roles: $roles,
            twoFactorConfirmed: $state->two_factor_secret !== null && $state->two_factor_confirmed_at !== null,
            passwordConfirmedAt: $passwordConfirmedAt,
            channel: $channel,
            ip: $ip,
            userAgent: $userAgent,
            requestId: $requestId,
            area: $area ?? (in_array(Role::Student, $roles, true) ? Area::Student : null),
        );
    }

    /** Suspended accounts get 403 access_revoked; deleted or missing accounts 401 account_deleted. */
    private static function assertStatus(?string $status): void
    {
        match (AccountStatus::tryFrom((string) $status)) {
            AccountStatus::Active => null,
            AccountStatus::Suspended => throw new AccessRevoked,
            default => throw new AccountDeleted,
        };
    }
}
