<?php

namespace App\Platform\Errors;

/** 503. The service must not serve right now, for example while the redaction ledger is ahead of the database. */
final class Unavailable extends AppError
{
    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message, $errorCode);
    }

    public function status(): int
    {
        return 503;
    }
}
