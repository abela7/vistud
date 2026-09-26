<?php

namespace App\Appearance;

use InvalidArgumentException;

/**
 * An sRGB colour with optional opacity, and the colour maths themes need:
 * WCAG contrast, OKLCH for deriving shades, sRGB mixing (how CSS gradients
 * interpolate by default) and compositing a translucent colour over another.
 */
final readonly class Color
{
    /** Channels 0–255, alpha 0–1. */
    public function __construct(public float $r, public float $g, public float $b, public float $alpha = 1.0) {}

    /** "#rrggbb" or "#rrggbbaa", nothing else (ADR 0003 §6.4). */
    public static function hex(string $hex): self
    {
        if (preg_match('/^#([0-9a-fA-F]{6})([0-9a-fA-F]{2})?$/', $hex, $m) !== 1) {
            throw new InvalidArgumentException('Colours are "#rrggbb" or "#rrggbbaa".');
        }
        [$r, $g, $b] = array_map('hexdec', str_split($m[1], 2));

        return new self($r, $g, $b, isset($m[2]) ? hexdec($m[2]) / 255 : 1.0);
    }

    public function toHex(): string
    {
        $channels = [$this->r, $this->g, $this->b];
        $hex = '#'.implode('', array_map(fn ($c) => sprintf('%02x', (int) round(max(0, min(255, $c)))), $channels));

        return $this->alpha < 1.0 ? $hex.sprintf('%02x', (int) round($this->alpha * 255)) : $hex;
    }

    /** WCAG 2.x relative luminance of the opaque colour. */
    public function luminance(): float
    {
        $lin = fn (float $c) => ($c /= 255) <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $lin($this->r) + 0.7152 * $lin($this->g) + 0.0722 * $lin($this->b);
    }

    /** WCAG contrast ratio; a translucent foreground is composited over the background first. */
    public static function contrast(self $foreground, self $background): float
    {
        $fg = $foreground->over($background);
        [$a, $b] = [$fg->luminance(), $background->luminance()];

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** This colour composited over an opaque background. */
    public function over(self $background): self
    {
        $a = $this->alpha;

        return new self(
            $this->r * $a + $background->r * (1 - $a),
            $this->g * $a + $background->g * (1 - $a),
            $this->b * $a + $background->b * (1 - $a),
        );
    }

    /** Interpolation in gamma-encoded sRGB, as CSS gradients do by default. */
    public static function mix(self $a, self $b, float $t): self
    {
        return new self(
            $a->r + ($b->r - $a->r) * $t,
            $a->g + ($b->g - $a->g) * $t,
            $a->b + ($b->b - $a->b) * $t,
            $a->alpha + ($b->alpha - $a->alpha) * $t,
        );
    }

    /** @return array{0: float, 1: float, 2: float} OKLCH: lightness 0–1, chroma, hue in degrees */
    public function toOklch(): array
    {
        $lin = fn (float $c) => ($c /= 255) <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        [$r, $g, $b] = [$lin($this->r), $lin($this->g), $lin($this->b)];

        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
        [$l, $m, $s] = [self::cbrt($l), self::cbrt($m), self::cbrt($s)];

        $L = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $A = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $B = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        $hue = rad2deg(atan2($B, $A));

        return [$L, sqrt($A * $A + $B * $B), $hue < 0 ? $hue + 360 : $hue];
    }

    /** From OKLCH, reducing chroma until the colour fits in sRGB. */
    public static function oklch(float $lightness, float $chroma, float $hue, float $alpha = 1.0): self
    {
        $lightness = max(0.0, min(1.0, $lightness));
        for ($c = $chroma; $c >= 0; $c -= 0.002) {
            $rgb = self::oklabToRgb($lightness, $c * cos(deg2rad($hue)), $c * sin(deg2rad($hue)));
            if ($rgb !== null) {
                return new self(...[...$rgb, $alpha]);
            }
        }

        return new self(...[...self::oklabToRgb($lightness, 0, 0) ?? [0, 0, 0], $alpha]);
    }

    public function withAlpha(float $alpha): self
    {
        return new self($this->r, $this->g, $this->b, $alpha);
    }

    /** @return array{0: float, 1: float, 2: float}|null 0–255 channels, or null when out of gamut */
    private static function oklabToRgb(float $L, float $a, float $b): ?array
    {
        $l = ($L + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($L - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($L - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $linear = [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
        $out = [];
        foreach ($linear as $c) {
            if ($c < -0.0005 || $c > 1.0005) {
                return null;
            }
            $c = max(0.0, min(1.0, $c));
            $out[] = 255 * ($c <= 0.0031308 ? 12.92 * $c : 1.055 * $c ** (1 / 2.4) - 0.055);
        }

        return $out;
    }

    private static function cbrt(float $x): float
    {
        return $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3);
    }
}
