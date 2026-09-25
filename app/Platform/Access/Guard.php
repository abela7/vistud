<?php

namespace App\Platform\Access;

use App\Platform\Errors\Forbidden;
use App\Platform\Errors\PasswordConfirmationRequired;
use App\Platform\Errors\TwoFactorRequired;

/**
 * The permission checks every application service runs itself (ADR 0003
 * §10.2–10.3). Middleware and screens repeat them for a better experience,
 * but these are the ones that count.
 *
 * Stable contract: docs/architecture/contracts.md.
 */
final class Guard
{
    /** An admin with confirmed 2FA. The system principal passes only where a service opts in. */
    public static function admin(Principal $principal, bool $allowSystem = false): void
    {
        if ($principal->isSystem()) {
            if ($allowSystem) {
                return;
            }
            throw new Forbidden('system_not_allowed', 'This action cannot be run from the console.');
        }
        if (! $principal->hasRole(Role::Admin)) {
            throw new Forbidden('admin_role_required');
        }
        if (! $principal->twoFactorConfirmed) {
            throw new TwoFactorRequired;
        }
    }

    /** A protected admin action: admin, 2FA, and a password confirmation in the last 10 minutes. */
    public static function protectedAdmin(Principal $principal, bool $allowSystem = false): void
    {
        self::admin($principal, $allowSystem);
        if (! $principal->isSystem() && ! $principal->hasRecentPasswordConfirmation()) {
            throw new PasswordConfirmationRequired;
        }
    }

    /** The principal's own learner stream. Admin status adds nothing here. */
    public static function learner(Principal $principal): LearnerScope
    {
        return LearnerScope::of($principal);
    }
}
