<?php

namespace App\Platform\Access;

use DateTimeImmutable;

/**
 * Who is acting, as every application service sees it. Adapters build it
 * (Identity's PrincipalFactory for users, Principal::system() for console
 * bootstrap commands) and pass it to services explicitly. Services never
 * read the session, the request or the authenticated user themselves.
 *
 * Stable contract: docs/architecture/contracts.md.
 */
final readonly class Principal
{
    /**
     * @param  list<Role>  $roles
     * @param  string  $channel  web · api · mcp · console · job
     * @param  Workspace|null  $workspace  the workspace the request was made in, when there is one
     */
    public function __construct(
        public ?string $userId,
        public ?string $learnerId,
        public array $roles,
        public bool $twoFactorConfirmed,
        public ?DateTimeImmutable $passwordConfirmedAt,
        public string $channel,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
        private bool $system = false,
        public ?Workspace $workspace = null,
    ) {}

    /**
     * The server itself, acting from a shell. Only the operations that ADR
     * 0003 §10.3 reserves for the console accept it (creating the first
     * admin, resetting 2FA); every other service refuses it.
     */
    public static function system(string $channel = 'console', ?string $requestId = null): self
    {
        return new self(null, null, [], false, null, $channel, requestId: $requestId, system: true);
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function hasRole(Role $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasRecentPasswordConfirmation(): bool
    {
        if ($this->passwordConfirmedAt === null) {
            return false;
        }
        $age = now()->getTimestamp() - $this->passwordConfirmedAt->getTimestamp();

        return $age >= 0 && $age <= (int) config('vistud.access.password_confirmation_seconds', 600);
    }

    /**
     * The role written to audit records when the service doesn't name one:
     * the workspace the user acted in, or their only role.
     */
    public function auditRole(): string
    {
        return match (true) {
            $this->system => 'system',
            $this->workspace === Workspace::Admin => 'admin',
            $this->hasRole(Role::Student) => 'student',
            $this->hasRole(Role::Admin) => 'admin',
            default => 'student',
        };
    }
}
