<?php

namespace Database\Factories;

use App\Models\User;
use App\Platform\Ids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Test and seed accounts. Factories write rows directly, for speed; code
 * under test always goes through the Identity services.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password-for-tests'),
            'remember_token' => Str::random(10),
            'status' => 'active',
        ];
    }

    /** A student account with a learner record. */
    public function student(string $timezone = 'UTC'): static
    {
        return $this->afterCreating(function (User $user) use ($timezone) {
            DB::table('user_roles')->insertOrIgnore(['user_id' => $user->id, 'role' => 'student', 'granted_at' => now()]);
            if (! DB::table('learners')->where('user_id', $user->id)->exists()) {
                DB::table('learners')->insert([
                    'id' => Ids::new(),
                    'user_id' => $user->id,
                    'timezone' => $timezone,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /** The admin role. Combine with twoFactor() for a usable admin. */
    public function admin(): static
    {
        return $this->afterCreating(function (User $user) {
            DB::table('user_roles')->insertOrIgnore(['user_id' => $user->id, 'role' => 'admin', 'granted_at' => now()]);
        });
    }

    /** Confirmed two-factor authentication with a real secret. */
    public function twoFactor(): static
    {
        return $this->state(fn () => [
            'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-one-aaaa', 'code-two-bbbb'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended', 'status_changed_at' => now()]);
    }

    public function deleted(): static
    {
        return $this->state(fn () => ['status' => 'deleted', 'status_changed_at' => now()]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
