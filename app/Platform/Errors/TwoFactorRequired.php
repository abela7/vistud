<?php

namespace App\Platform\Errors;

/** 403. An admin without confirmed two-factor authentication (ADR 0003 D5). */
final class TwoFactorRequired extends AppError
{
    public function __construct()
    {
        parent::__construct('Two-factor authentication must be set up first.', 'two_factor_required');
    }

    public function status(): int
    {
        return 403;
    }
}
