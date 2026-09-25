<?php

namespace App\Platform;

use Illuminate\Support\Str;

/**
 * Identifier rules (docs/architecture/conventions.md).
 *
 * - New IDs are UUIDv7, whether a client or the server creates them.
 * - The domain accepts any ID *token*: letters, digits, dot, underscore and
 *   hyphen, up to 64 characters. That keeps fixtures such as "T-LEFT"
 *   readable and keeps references ("topic:<id>#<locator>") unambiguous.
 *   The JSON API may require UUIDs for IDs a client creates.
 */
final class Ids
{
    public const TOKEN_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    public static function new(): string
    {
        return (string) Str::uuid7();
    }

    public static function isToken(mixed $value): bool
    {
        return is_string($value) && preg_match(self::TOKEN_PATTERN, $value) === 1;
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && Str::isUuid($value);
    }
}
