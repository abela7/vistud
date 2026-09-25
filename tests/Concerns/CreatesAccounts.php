<?php

namespace Tests\Concerns;

use App\Identity\PrincipalFactory;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Access\Workspace;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Accounts and principals for tests. Rows come from factories; code under test uses the services. */
trait CreatesAccounts
{
    /** Models are re-read, so they hold every column, as a logged-in user would. */
    protected function student(array $attributes = []): User
    {
        return User::factory()->student()->create($attributes)->fresh();
    }

    /** An admin with confirmed 2FA. Also a student unless $student is false. */
    protected function admin(bool $student = true, array $attributes = []): User
    {
        $factory = User::factory()->admin()->twoFactor();

        return ($student ? $factory->student() : $factory)->create($attributes)->fresh();
    }

    /** A principal for $user, with a password confirmation just now unless told otherwise. */
    protected function principal(User $user, bool $confirmed = true, ?Workspace $workspace = null, string $channel = 'web'): Principal
    {
        return app(PrincipalFactory::class)->forUser(
            $user->fresh(),
            $channel,
            passwordConfirmedAt: $confirmed ? new DateTimeImmutable('@'.now()->getTimestamp()) : null,
            workspace: $workspace,
        );
    }

    /** Session values for an HTTP request with a recent password confirmation. */
    protected function confirmedSession(?Workspace $workspace = null): array
    {
        return array_filter([
            PrincipalFactory::PASSWORD_CONFIRMED_KEY => now()->getTimestamp(),
            PrincipalFactory::WORKSPACE_KEY => $workspace?->value,
        ]);
    }

    /** @return list<object> audit rows for an action, oldest first */
    protected function auditRows(string $action): array
    {
        return DB::table('audit_log')->where('action', $action)->orderBy('id')->get()->all();
    }
}
