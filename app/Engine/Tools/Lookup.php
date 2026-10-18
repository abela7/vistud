<?php

namespace App\Engine\Tools;

use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Folders;

/** Finds the one thing the model named: by id, by its exact name, or by a part of its name; says when it can't. */
final class Lookup
{
    /**
     * @template T of object
     *
     * @param  list<T>  $items
     * @param  callable(T): string  $label
     * @return T|string the item, or words saying why none was picked
     */
    public static function one(array $items, string $wanted, callable $label, string $what): object|string
    {
        $wanted = trim($wanted);
        if ($wanted === '') {
            return "Say which {$what}.";
        }
        foreach ($items as $item) {
            if ($item->id === $wanted) {
                return $item;
            }
        }
        $key = self::key($wanted);
        $exact = array_values(array_filter($items, fn ($item) => self::key($label($item)) === $key));
        if (count($exact) === 1) {
            return $exact[0];
        }
        $near = $exact !== [] ? $exact : array_values(array_filter($items, fn ($item) => mb_stripos($label($item), $wanted) !== false));
        if (count($near) === 1) {
            return $near[0];
        }
        if ($near === []) {
            return "There is no {$what} called \"{$wanted}\" in this course.";
        }

        // Two things can share a name (an "Introduction" in two modules): their ids tell them apart.
        return "Several {$what}s match \"{$wanted}\": ".implode('; ', array_map(fn ($item) => '"'.$label($item).'" (id '.$item->id.')', array_slice($near, 0, 6))).'. Say which one, by its id.';
    }

    /**
     * Strict resolution for MCP writes: accepts an id or an exact normalised name only; never a partial match.
     *
     * @template T of object
     *
     * @param  list<T>  $items
     * @param  callable(T): string  $label
     * @return T|string
     */
    public static function exact(array $items, string $wanted, callable $label, string $what): object|string
    {
        $wanted = trim($wanted);
        if ($wanted === '') {
            return "Say which {$what}.";
        }
        foreach ($items as $item) {
            if ($item->id === $wanted) {
                return $item;
            }
        }
        $key = self::key($wanted);
        $exact = array_values(array_filter($items, fn ($item) => self::key($label($item)) === $key));
        if (count($exact) === 1) {
            return $exact[0];
        }
        $near = $exact !== [] ? $exact : array_values(array_filter($items, fn ($item) => mb_stripos($label($item), $wanted) !== false));
        if ($near === []) {
            return "There is no {$what} called \"{$wanted}\" in this course.";
        }
        if (count($near) === 1) {
            return "There is no {$what} called \"{$wanted}\" in this course (closest: \"{$label($near[0])}\" (id {$near[0]->id})). Say its exact name or its id.";
        }

        return "Several {$what}s match \"{$wanted}\": ".implode('; ', array_map(fn ($item) => '"'.$label($item).'" (id '.$item->id.')', array_slice($near, 0, 6))).'. Say which one, by its id.';
    }

    /**
     * @template T of object
     *
     * @param  list<T>  $items
     * @param  callable(T): string  $label
     * @return T|string
     */
    public static function forWrite(Principal $by, array $items, string $wanted, callable $label, string $what): object|string
    {
        return $by->channel === 'mcp'
            ? self::exact($items, $wanted, $label, $what)
            : self::one($items, $wanted, $label, $what);
    }

    /** A name as compared: case, surrounding quotes and runs of spaces aside. */
    public static function key(string $name): string
    {
        $bare = (string) preg_replace('/^[\s"\'`“”‘’]+|[\s"\'`“”‘’]+$/u', '', $name);

        return mb_strtolower((string) preg_replace('/\s+/u', ' ', $bare));
    }

    /**
     * The same, looking first among what is in the session's folder (docs/specs/vistud-2-blueprint.md, Phase 9): a name
     * that fits one thing there is that thing, even when the course has others like it; otherwise the whole course.
     *
     * @template T of object
     *
     * @param  list<T>  $items  everything in the course
     * @param  ?list<string>  $folderIds  the session's folder and those inside it (null: no folder)
     * @param  callable(T): string  $label
     * @return T|string
     */
    public static function here(array $items, ?array $folderIds, string $wanted, callable $label, string $what): object|string
    {
        if ($folderIds !== null) {
            $inside = array_values(array_filter($items, fn ($item) => ($item->folderId ?? null) !== null && in_array($item->folderId, $folderIds, true)));
            $found = $inside === [] ? null : self::one($inside, $wanted, $label, $what);
            if (is_object($found)) {
                return $found;
            }
        }

        return self::one($items, $wanted, $label, $what);
    }

    /**
     * @template T of object
     *
     * @param  list<T>  $items
     * @param  ?list<string>  $folderIds
     * @param  callable(T): string  $label
     * @return T|string
     */
    public static function hereExact(array $items, ?array $folderIds, string $wanted, callable $label, string $what): object|string
    {
        if ($folderIds !== null) {
            $inside = array_values(array_filter($items, fn ($item) => ($item->folderId ?? null) !== null && in_array($item->folderId, $folderIds, true)));
            $found = $inside === [] ? null : self::exact($inside, $wanted, $label, $what);
            if (is_object($found)) {
                return $found;
            }
        }

        return self::exact($items, $wanted, $label, $what);
    }

    /**
     * @template T of object
     *
     * @param  list<T>  $items
     * @param  ?list<string>  $folderIds
     * @param  callable(T): string  $label
     * @return T|string
     */
    public static function hereForWrite(Principal $by, array $items, ?array $folderIds, string $wanted, callable $label, string $what): object|string
    {
        return $by->channel === 'mcp'
            ? self::hereExact($items, $folderIds, $wanted, $label, $what)
            : self::here($items, $folderIds, $wanted, $label, $what);
    }

    /**
     * The session's folder and the folders inside it, or null when the chat studies in no folder (or the folder is gone).
     *
     * @return ?list<string>
     */
    public static function folder(Folders $folders, Principal $by, Context $context): ?array
    {
        if ($context->folderId === null) {
            return null;
        }
        try {
            return $folders->within($by, $context->folderId);
        } catch (NotFound) {
            return null;
        }
    }

    /** Compact JSON for the model. */
    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The text or null, as an argument the model gave. */
    public static function text(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : (is_int($value) || is_float($value) ? (string) $value : null);
    }
}
