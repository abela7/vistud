<?php

namespace App\Identity;

use App\Models\User;
use App\Platform\Access\LearnerKeyStore;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Role;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\Conflict;
use App\Platform\Ids;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Creates a user, and for students their learner record. Internal to
 * Identity: callers are Accounts::create() and Invitations::accept(), which
 * do the permission checks. Never grants admin.
 */
final class AccountFactory
{
    public function __construct(private RoleStore $roles) {}

    public function create(string $name, string $email, string $password, bool $student, ?string $timezone, ?string $grantedBy, ?Closure $afterCreate = null): User
    {
        $email = self::normaliseEmail($email);
        $timezone ??= 'UTC';

        Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'timezone' => $timezone],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => PasswordRules::rules(),
                'timezone' => ['required', 'timezone:all'],
            ],
        )->validate();

        if (User::query()->where('email', $email)->exists()) {
            throw new Conflict('email_taken', 'An account with this email address already exists.');
        }

        if (DB::transactionLevel() !== 0 && ! app()->runningUnitTests()) {
            throw new \LogicException('Provision the learner key before opening the account transaction.');
        }
        $learnerId = $student ? Ids::new() : null;
        $scope = $learnerId === null ? null : LearnerScope::forJob($learnerId);
        $createdKey = $scope !== null && app(LearnerKeyStore::class)->provision($scope);
        try {
            return DB::transaction(function () use ($name, $email, $password, $student, $timezone, $grantedBy, $learnerId, $afterCreate) {
                $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
                try {
                    $user->forceFill(['email_verified_at' => now(), 'status' => AccountStatus::Active->value])->save();
                } catch (UniqueConstraintViolationException) {
                    // Another request created the same email between the check and the insert.
                    throw new Conflict('email_taken', 'An account with this email address already exists.');
                }

                if ($student) {
                    $this->roles->add($user->id, Role::Student, $grantedBy);
                    DB::table('learners')->insert([
                        'id' => $learnerId,
                        'user_id' => $user->id,
                        'timezone' => $timezone,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'key_provisioned_at' => now(),
                    ]);
                }

                $afterCreate?->__invoke($user);

                return $user;
            });
        } catch (Throwable $e) {
            if ($createdKey) {
                try {
                    // A lost commit acknowledgement is ambiguous. Delete only when
                    // rollback is confirmed; otherwise keep a harmless possible orphan.
                    if (! DB::table('learners')->where('id', $scope->learnerId)->exists()) {
                        LearnerTables::query($scope, 'learner_keys')->delete();
                    }
                } catch (Throwable) {
                    // The offline provisioner can reconcile it once databases recover.
                }
            }
            throw $e;
        }
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
