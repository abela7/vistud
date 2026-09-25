<?php

namespace App\Platform\Errors;

/** 403. The principal is known but may not do this, for example a student on an admin route. */
final class Forbidden extends AppError
{
    public function __construct(string $errorCode = 'forbidden', string $message = 'You do not have permission to do this.')
    {
        parent::__construct($message, $errorCode);
    }

    public function status(): int
    {
        return 403;
    }
}
