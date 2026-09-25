<?php

namespace App\Identity;

use App\Platform\Access\Role;

/**
 * What an admin may see about an account: details, never content
 * (ADR 0003 §10.2). Adding a field here needs the PM's review.
 */
final readonly class AccountDetails
{
    /** @param list<Role> $roles */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public AccountStatus $status,
        public array $roles,
        public bool $twoFactorConfirmed,
        public string $createdAt,
        public ?string $statusChangedAt,
        public ?string $lastActiveAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'roles' => array_map(fn (Role $role) => $role->value, $this->roles),
            'two_factor_confirmed' => $this->twoFactorConfirmed,
            'created_at' => $this->createdAt,
            'status_changed_at' => $this->statusChangedAt,
            'last_active_at' => $this->lastActiveAt,
        ];
    }
}
