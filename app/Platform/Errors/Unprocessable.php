<?php

namespace App\Platform\Errors;

/**
 * 422. The request is well-formed but breaks a domain rule. Codes in use:
 * invalid_entry, unknown_reference, review_not_allowed, invitation_invalid.
 * Details name fields, never their values.
 */
final class Unprocessable extends AppError
{
    public function __construct(string $errorCode, string $message, array $details = [])
    {
        parent::__construct($message, $errorCode, $details);
    }

    public function status(): int
    {
        return 422;
    }
}
