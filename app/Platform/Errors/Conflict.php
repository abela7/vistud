<?php

namespace App\Platform\Errors;

/**
 * 409. The request clashes with the current state. Codes in use:
 * version_conflict, id_conflict, capture_key_conflict, last_admin.
 */
final class Conflict extends AppError
{
    public function __construct(string $errorCode, string $message, array $details = [])
    {
        parent::__construct($message, $errorCode, $details);
    }

    public function status(): int
    {
        return 409;
    }
}
