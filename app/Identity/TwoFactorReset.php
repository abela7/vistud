<?php

namespace App\Identity;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Jobs\RevokeConnections;
use App\Models\User;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use Illuminate\Support\Facades\DB;

/**
 * Recovering admin access (ADR 0003 §10.3): another admin resets a user's
 * 2FA, or, when no admin is available, the console does. The user must enrol
 * again before entering the admin area, and is logged out everywhere.
 */
final class TwoFactorReset
{
    public function __construct(private AuditLog $audit) {}

    public function reset(Principal $by, string $userId): void
    {
        Guard::protectedAdmin($by, allowSystem: true);

        DB::transaction(function () use ($by, $userId) {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first() ?? throw new NotFound;

            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'remember_token' => null,
            ])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            // Connected AI apps sign in again after a 2FA reset (docs/specs/mcp-connections-plan.md M6.1).
            RevokeConnections::dispatchSync($user->id, 'two_factor_reset');

            $this->audit->record($by, AuditAction::TWO_FACTOR_RESET, 'user', $user->id, role: $by->isSystem() ? null : 'admin');
        });
    }
}
