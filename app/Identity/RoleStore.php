<?php

namespace App\Identity;

use App\Platform\Access\Role;
use Illuminate\Support\Facades\DB;

/** Reads and writes user_roles and learner records. Internal to Identity. */
final class RoleStore
{
    /** @return list<Role> */
    public function rolesOf(string $userId): array
    {
        return DB::table('user_roles')
            ->where('user_id', $userId)
            ->orderBy('role')
            ->pluck('role')
            ->map(fn (string $role) => Role::from($role))
            ->all();
    }

    public function has(string $userId, Role $role): bool
    {
        return DB::table('user_roles')->where('user_id', $userId)->where('role', $role->value)->exists();
    }

    /** @return bool whether the role was newly added */
    public function add(string $userId, Role $role, ?string $grantedBy): bool
    {
        return DB::table('user_roles')->insertOrIgnore([
            'user_id' => $userId,
            'role' => $role->value,
            'granted_at' => now(),
            'granted_by' => $grantedBy,
        ]) === 1;
    }

    /** @return bool whether the role was removed */
    public function remove(string $userId, Role $role): bool
    {
        return DB::table('user_roles')->where('user_id', $userId)->where('role', $role->value)->delete() === 1;
    }

    public function learnerIdOf(string $userId): ?string
    {
        return DB::table('learners')->where('user_id', $userId)->value('id');
    }
}
