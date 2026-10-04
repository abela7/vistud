<?php

namespace App\Engine\Tools;

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
