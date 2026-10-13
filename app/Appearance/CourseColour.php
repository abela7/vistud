<?php

namespace App\Appearance;

/**
 * A course's colour: one of the theme's categories (Theme::CATEGORIES, `.ws-colour-{name}` in components.css), or a
 * colour of the student's own, kept as `#rrggbb` (the owner's ask, 2026-10-12). Like the theme writer, this turns data
 * into CSS, so the components still hold no colour: a custom colour becomes the same two variables a category sets,
 * --ws and --ws-subtle, each mixed with the theme's own text and surface. The darker or lighter the colour, the more of
 * the theme's text it takes, so its icon and words stay readable on its tint in light and dark alike.
 */
final class CourseColour
{
    private const HEX = '/^#[0-9a-f]{6}$/';

    public static function isCustom(?string $colour): bool
    {
        return is_string($colour) && preg_match(self::HEX, $colour) === 1;
    }

    /** A category, or a custom colour written as six hex digits (any case, the # optional): the stored form, or null. */
    public static function normalise(mixed $colour): ?string
    {
        if (! is_string($colour)) {
            return null;
        }
        $colour = strtolower(trim($colour));
        if (in_array($colour, Theme::CATEGORIES, true)) {
            return $colour;
        }
        $colour = str_starts_with($colour, '#') ? $colour : '#'.$colour;

        return self::isCustom($colour) ? $colour : null;
    }

    /** The class that gives an element the course's colour. */
    public static function classFor(string $colour): string
    {
        return self::isCustom($colour) ? 'ws-colour-custom' : "ws-colour-{$colour}";
    }

    /** The variables a custom colour sets on that element; empty for a category, whose class sets them. */
    public static function style(string $colour): string
    {
        if (! self::isCustom($colour)) {
            return '';
        }
        // Between 35 % of the colour (white or black) and 70 % (a mid tone, by how light it looks): the rest is the theme's text.
        $strong = (int) round(70 - 35 * abs(self::lightness($colour) - 50) / 50);

        return "--ws: color-mix(in oklab, {$colour} {$strong}%, var(--text)); --ws-subtle: color-mix(in oklab, {$colour} 16%, var(--surface-raised));";
    }

    /** How light a colour looks (CIE L*), 0 for black to 100 for white; a mid grey is 50. */
    private static function lightness(string $colour): float
    {
        $y = self::luminance($colour);

        return $y > 216 / 24389 ? 116 * $y ** (1 / 3) - 16 : $y * 24389 / 27;
    }

    /** Relative luminance (WCAG 2), 0 for black to 1 for white. */
    private static function luminance(string $colour): float
    {
        $channel = function (string $hex): float {
            $value = hexdec($hex) / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel(substr($colour, 1, 2)) + 0.7152 * $channel(substr($colour, 3, 2)) + 0.0722 * $channel(substr($colour, 5, 2));
    }
}
