<?php

namespace App\Engine\Jobs;

use RuntimeException;

/**
 * A job that found nothing to do (a picture with no text to read, a file read already): not a failure. The run's
 * row says `skipped` with this code.
 */
final class Skipped extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
