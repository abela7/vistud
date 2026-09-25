<?php

namespace App\Identity\Fortify;

use App\Identity\PasswordRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    /** @param array<string, string> $input */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, ['password' => [...PasswordRules::rules(), 'confirmed']])->validate();

        $user->forceFill(['password' => $input['password']])->save();
    }
}
