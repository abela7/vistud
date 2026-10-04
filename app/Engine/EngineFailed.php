<?php

namespace App\Engine;

use App\Platform\Errors\AppError;

/**
 * The engine couldn't answer: no key is set, the key was refused, the account is out of credit, the service is
 * busy or down, or it couldn't be reached. The message says which, in words the student can act on; it never
 * carries the key or what was sent.
 */
final class EngineFailed extends AppError
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
