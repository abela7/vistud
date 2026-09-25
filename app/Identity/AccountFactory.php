<?php

namespace App\Identity;

use App\Models\User;
use App\Platform\Access\Role;
use App\Platform\Errors\Conflict;
use App\Platform\Ids;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Creates a user, and for students their learner record. Internal to
 * Identity: callers are Accounts::create() and Invitations::accept(), which
 * do the permission checks. Never grants admin.
 */
final class AccountFactory
{
    public function __construct(private RoleStore $roles) {}

    public function create(string $name, string $email, string $password, bool $student, ?string $timezone, ?string $grantedBy): User
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

        return DB::transaction(function () use ($name, $email, $password, $student, $timezone, $grantedBy) {
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
                    'id' => Ids::new(),
                    'user_id' => $user->id,
                    'timezone' => $timezone,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $user;
        });
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
