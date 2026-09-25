<?php

namespace App\Identity;

use Illuminate\Validation\Rules\Password;

/** Password rules for every path that sets one: the console, invitations and resets. */
final class PasswordRules
{
    /** @return list<mixed> */
    public static function rules(): array
    {
        // No "uncompromised" check: it calls an external service, and the
        // platform is self-hosted with no required outbound access.
        return ['required', 'string', Password::min(12)->max(255)];
    }
}
