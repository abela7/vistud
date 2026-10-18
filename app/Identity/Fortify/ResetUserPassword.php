<?php

namespace App\Identity\Fortify;

use App\Identity\PasswordRules;
use App\Jobs\RevokeConnections;
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
        // A new password signs every connected AI app out (docs/specs/mcp-connections-plan.md M6.1).
        RevokeConnections::dispatchSync($user->id, 'password');
    }
}
