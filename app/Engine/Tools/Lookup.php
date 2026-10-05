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
        $exact = array_values(array_filter($items, fn ($item) => mb_strtolower($label($item)) === mb_strtolower($wanted)));
        if (count($exact) === 1) {
            return $exact[0];
        }
        $partial = $exact !== [] ? $exact : array_values(array_filter($items, fn ($item) => mb_stripos($label($item), $wanted) !== false));
        if (count($partial) === 1) {
            return $partial[0];
        }
        if ($partial === []) {
            return "There is no {$what} called \"{$wanted}\" in this course.";
        }

        return "Several {$what}s match \"{$wanted}\": ".implode('; ', array_map($label, array_slice($partial, 0, 6))).'. Say which one.';
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
