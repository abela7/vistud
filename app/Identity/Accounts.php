<?php

namespace App\Identity;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Models\User;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\PasswordConfirmationRequired;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Account administration (ADR 0003 §10.2–10.3). Every method checks its own
 * permissions; admin methods return account details, never learning content.
 */
final class Accounts
{
    public function __construct(
        private AccountFactory $factory,
        private AdminSafeguard $safeguard,
        private RoleStore $roles,
        private AuditLog $audit,
    ) {}

    /**
     * Protected admin action, or the console (the first account). Creates a
     * student with a learner record unless $student is false. Never grants admin.
     */
    public function create(Principal $by, string $name, string $email, string $password, bool $student = true, ?string $timezone = null): User
    {
        Guard::protectedAdmin($by, allowSystem: true);

        return DB::transaction(function () use ($by, $name, $email, $password, $student, $timezone) {
            $user = $this->factory->create($name, $email, $password, $student, $timezone, $by->userId);
            $this->audit->record($by, AuditAction::ACCOUNT_CREATED, 'user', $user->id, ['student' => $student], role: $this->adminRole($by));

            return $user;
        });
    }

    public function suspend(Principal $by, string $userId): void
    {
        Guard::protectedAdmin($by);

        DB::transaction(function () use ($by, $userId) {
            $this->safeguard->assertNotLastActiveAdmin($userId);
            $user = $this->lockUser($userId);
            if ($user->status === AccountStatus::Suspended->value) {
                return;
            }
            $this->assertNotDeleted($user);

            $this->changeStatus($user, AccountStatus::Suspended);
            $this->endSessions($user);
            $this->audit->record($by, AuditAction::ACCOUNT_SUSPENDED, 'user', $user->id, role: 'admin');
        }, 3);
    }

    public function reactivate(Principal $by, string $userId): void
    {
        Guard::protectedAdmin($by);

        DB::transaction(function () use ($by, $userId) {
            $user = $this->lockUser($userId);
            if ($user->status === AccountStatus::Active->value) {
                return;
            }
            $this->assertNotDeleted($user);

            $this->changeStatus($user, AccountStatus::Active);
            $this->audit->record($by, AuditAction::ACCOUNT_REACTIVATED, 'user', $user->id, role: 'admin');
        }, 3);
    }

    /**
     * Marks the account deleted and ends its sessions. Allowed for a protected
     * admin, or for the account's own user with a recent password
     * confirmation. The erasure job (ADR 0001, before another real learner
     * joins) removes the data itself.
     */
    public function requestDeletion(Principal $by, string $userId): void
    {
        $self = ! $by->isSystem() && $by->userId === $userId;
        if ($self) {
            if (! $by->hasRecentPasswordConfirmation()) {
                throw new PasswordConfirmationRequired;
            }
        } else {
            Guard::protectedAdmin($by);
        }

        DB::transaction(function () use ($by, $userId, $self) {
            $this->safeguard->assertNotLastActiveAdmin($userId);
            $user = $this->lockUser($userId);
            if ($user->status === AccountStatus::Deleted->value) {
                return;
            }

            $this->changeStatus($user, AccountStatus::Deleted);
            $this->endSessions($user);
            $this->audit->record($by, AuditAction::ACCOUNT_DELETION_REQUESTED, 'user', $user->id, ['self' => $self], role: $self ? null : 'admin');
        }, 3);
    }

    /** Admin with 2FA. Details only, never content. */
    public function details(Principal $by, string $userId): AccountDetails
    {
        Guard::admin($by);

        $user = User::query()->find($userId) ?? throw new NotFound;

        return $this->toDetails($user);
    }

    /**
     * Admin with 2FA. Oldest first, by ID. $search matches part of a name or
     * an email address.
     *
     * @return array{data: list<AccountDetails>, next_cursor: ?string}
     */
    public function list(Principal $by, ?string $cursor = null, int $limit = 50, ?string $search = null): array
    {
        Guard::admin($by);

        $limit = max(1, min($limit, 200));
        $search = trim((string) $search);
        $pattern = '%'.addcslashes(mb_substr($search, 0, 100), '\\%_').'%';
        $users = User::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', $pattern)->orWhere('email', 'like', $pattern)))
            ->when($cursor !== null, fn ($q) => $q->where('id', '>', $cursor))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $next = null;
        if ($users->count() > $limit) {
            $users = $users->slice(0, $limit);
            $next = $users->last()->id;
        }

        return ['data' => $users->map(fn (User $user) => $this->toDetails($user))->values()->all(), 'next_cursor' => $next];
    }

    private function toDetails(User $user): AccountDetails
    {
        $lastActive = DB::table('sessions')->where('user_id', $user->id)->max('last_activity');

        return new AccountDetails(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            status: AccountStatus::from($user->status),
            roles: $this->roles->rolesOf($user->id),
            twoFactorConfirmed: $user->two_factor_confirmed_at !== null,
            createdAt: $user->created_at->toIso8601String(),
            statusChangedAt: $user->status_changed_at?->toIso8601String(),
            lastActiveAt: $lastActive === null ? null : CarbonImmutable::createFromTimestamp((int) $lastActive)->toIso8601String(),
        );
    }

    private function lockUser(string $userId): User
    {
        return User::query()->whereKey($userId)->lockForUpdate()->first() ?? throw new NotFound;
    }

    private function assertNotDeleted(User $user): void
    {
        if ($user->status === AccountStatus::Deleted->value) {
            throw new Conflict('target_deleted', 'That account has been deleted.');
        }
    }

    private function changeStatus(User $user, AccountStatus $status): void
    {
        $user->forceFill(['status' => $status->value, 'status_changed_at' => now()])->save();
    }

    /** Ends every session of the user: database sessions and "remember me". */
    private function endSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => null])->save();
    }

    private function adminRole(Principal $by): ?string
    {
        return $by->isSystem() ? null : 'admin';
    }
}
