<?php

namespace App\Platform\Errors;

/**
 * 404. Used both for records that don't exist and for records that belong to
 * another learner (ADR 0003 D6). The two must be indistinguishable, so this
 * error never carries details about which case applied.
 */
final class NotFound extends AppError
{
    public function __construct()
    {
        parent::__construct('Not found.', 'not_found');
    }

    public function status(): int
    {
        return 404;
    }
}
