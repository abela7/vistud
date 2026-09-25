<?php

namespace App\Brain\Journal;

use InvalidArgumentException;

/**
 * An entry that breaks an ADR 0002 contract. The message and field name
 * never include the submitted value.
 */
final class InvalidEntry extends InvalidArgumentException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
