<?php

namespace App\Identity;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Models\User;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Access\Role;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use Illuminate\Support\Facades\DB;

/**
 * Granting and revoking admin (ADR 0003 §10.2–10.3). Ordinary accounts can
 * never reach these: both need a protected admin, and granting from the
 * console needs shell access to the server.
 */
final class Roles
{
    public function __construct(
        private RoleStore $roles,
        private AdminSafeguard $safeguard,
        private AuditLog $audit,
    ) {}

    public function grantAdmin(Principal $by, string $userId): void
    {
        Guard::protectedAdmin($by, allowSystem: true);

        DB::transaction(function () use ($by, $userId) {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first() ?? throw new NotFound;
            if ($user->status === AccountStatus::Deleted->value) {
                throw new Conflict('target_deleted', 'That account has been deleted.');
            }
            if ($this->roles->add($user->id, Role::Admin, $by->userId)) {
                $this->audit->record($by, AuditAction::ROLE_GRANTED, 'user', $user->id, ['role' => 'admin'], role: $by->isSystem() ? null : 'admin');
            }
        }, 3);
    }

    public function revokeAdmin(Principal $by, string $userId): void
    {
        Guard::protectedAdmin($by);

        DB::transaction(function () use ($by, $userId) {
            $this->safeguard->assertNotLastActiveAdmin($userId);
            User::query()->whereKey($userId)->lockForUpdate()->first() ?? throw new NotFound;

            if ($this->roles->remove($userId, Role::Admin)) {
                $this->audit->record($by, AuditAction::ROLE_REVOKED, 'user', $userId, ['role' => 'admin'], role: 'admin');
            }
        }, 3);
    }
}
