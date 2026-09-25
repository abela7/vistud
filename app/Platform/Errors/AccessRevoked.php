<?php

namespace App\Platform\Errors;

/** 403 with code access_revoked. The account is suspended; clients stop retrying for good (ADR 0003 §5.3). */
final class AccessRevoked extends AppError
{
    public function __construct()
    {
        parent::__construct('Access to this account has been removed.', 'access_revoked');
    }

    public function status(): int
    {
        return 403;
    }
}
