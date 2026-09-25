<?php

namespace App\Brain\Journal;

/**
 * References look like "topic:T-LEFT", "event:E6" or "source:SRC-7#00:34:10".
 */
final class Ref
{
    /** @return array{0: string, 1: string} type and id, with any #locator removed */
    public static function parse(string $ref): array
    {
        $parts = explode(':', $ref, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidEntry("Malformed reference '{$ref}'.");
        }

        return [$parts[0], explode('#', $parts[1], 2)[0]];
    }

    public static function type(string $ref): string
    {
        return self::parse($ref)[0];
    }

    public static function id(string $ref): string
    {
        return self::parse($ref)[1];
    }

    public static function make(string $type, string $id): string
    {
        return $type.':'.$id;
    }
}
