<?php

namespace App\Platform\Errors;

/** 410. The record was trashed, deleted or redacted; details.reason says which (ADR 0003 §5.4). */
final class Gone extends AppError
{
    public function __construct(string $reason)
    {
        parent::__construct('This item is no longer available.', 'gone', ['reason' => $reason]);
    }

    public function status(): int
    {
        return 410;
    }
}
