<?php

namespace App\Platform\Errors;

/** 423. A protected action needs a password confirmation from the last 10 minutes (ADR 0003 §10.2). */
final class PasswordConfirmationRequired extends AppError
{
    public function __construct()
    {
        parent::__construct('Confirm your password to continue.', 'password_confirmation_required');
    }

    public function status(): int
    {
        return 423;
    }
}
