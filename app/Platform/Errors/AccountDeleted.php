<?php

namespace App\Platform\Errors;

/** 401 with code account_deleted. Clients purge every draft for the account (ADR 0003 §5.3). */
final class AccountDeleted extends AppError
{
    public function __construct()
    {
        parent::__construct('This account no longer exists.', 'account_deleted');
    }

    public function status(): int
    {
        return 401;
    }
}
