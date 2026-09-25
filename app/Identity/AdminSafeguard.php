<?php

namespace App\Identity;

use App\Platform\Errors\Conflict;
use Illuminate\Support\Facades\DB;

/**
 * The last-admin safeguard (ADR 0003 §10.3): no operation may leave zero
 * active admins. An active admin has the admin role and an active account.
 *
 * lockActiveAdmins() must run inside the caller's transaction, before the
 * caller locks anything else. It locks every active admin's rows in ID order,
 * so two requests that would each remove one of the last two admins run one
 * after the other, and the second sees the first one's result.
 */
final class AdminSafeguard
{
    /** @return list<string> the active admins' user IDs, locked until the transaction ends */
    public function lockActiveAdmins(): array
    {
        return DB::table('user_roles')
            ->join('users', 'users.id', '=', 'user_roles.user_id')
            ->where('user_roles.role', 'admin')
            ->where('users.status', AccountStatus::Active->value)
            ->orderBy('users.id')
            ->lockForUpdate()
            ->pluck('users.id')
            ->all();
    }

    /** Refuses when removing $userId from the active admins would leave none. */
    public function assertNotLastActiveAdmin(string $userId): void
    {
        $admins = $this->lockActiveAdmins();

        if (in_array($userId, $admins, true) && count($admins) <= 1) {
            throw new Conflict('last_admin', 'This would leave no active admin.');
        }
    }
}
