<?php

namespace App\Platform\Errors;

use RuntimeException;
use Throwable;

/**
 * An expected failure that a service reports to its caller. Every adapter
 * (Livewire, the JSON API, MCP, the console) turns it into its own response;
 * the JSON form is the error envelope (docs/architecture/conventions.md).
 *
 * Messages and details must never contain personal content: they are sent
 * to clients and may be logged. Use IDs, field names and codes only.
 */
abstract class AppError extends RuntimeException
{
    /**
     * @param  string  $errorCode  stable, machine-readable code, for example "not_found"
     * @param  array<string, mixed>  $details  IDs, field names and codes only
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The HTTP status this error maps to. */
    abstract public function status(): int;
}
