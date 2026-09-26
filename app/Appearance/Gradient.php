<?php

namespace App\Appearance;

use InvalidArgumentException;

/**
 * A theme's gradient: a linear angle and its colour stops, or a solid fill.
 * Themes store these as data; components only ever say var(--grad-…).
 *
 * Data form: {"angle": 135, "stops": [["#003272", 0], ["#006e91", 60], ["#007f82", 100]]}
 * or, for a solid fill, {"solid": "#003272"}.
 */
final readonly class Gradient
{
    /** @param list<array{0: Color, 1: float}> $stops colour and position 0–100, ascending */
    private function __construct(public float $angle, public array $stops, public bool $solid) {}

    public static function fromData(array $data): self
    {
        if (array_key_exists('solid', $data)) {
            if (count($data) !== 1 || ! is_string($data['solid'])) {
                throw new InvalidArgumentException('A solid fill is {"solid": "#rrggbb"} and nothing else.');
            }

            return self::solid(Color::hex($data['solid']));
        }
        if (array_diff(array_keys($data), ['angle', 'stops']) !== []) {
            throw new InvalidArgumentException('A gradient has an angle and stops, and nothing else.');
        }

        $angle = $data['angle'] ?? null;
        $stops = $data['stops'] ?? null;
        if (! is_int($angle) && ! is_float($angle) || $angle < 0 || $angle > 360) {
            throw new InvalidArgumentException('A gradient angle is 0–360 degrees.');
        }
        if (! is_array($stops) || count($stops) < 2 || count($stops) > 6) {
            throw new InvalidArgumentException('A gradient has 2–6 stops.');
        }
        $parsed = [];
        $previous = -1.0;
        foreach ($stops as $stop) {
            if (! is_array($stop) || ! array_is_list($stop) || count($stop) !== 2 || ! is_string($stop[0])) {
                throw new InvalidArgumentException('A stop is ["#rrggbb", position].');
            }
            [$hex, $at] = $stop;
            if (! is_int($at) && ! is_float($at) || $at < $previous || $at > 100) {
                throw new InvalidArgumentException('Stop positions are 0–100 and ascending.');
            }
            $parsed[] = [Color::hex($hex), (float) $at];
            $previous = (float) $at;
        }

        return new self((float) $angle, $parsed, false);
    }

    public static function solid(Color $color): self
    {
        return new self(0, [[$color, 0.0], [$color, 100.0]], true);
    }

    /** @param list<array{0: Color, 1: float}> $stops */
    public static function linear(float $angle, array $stops): self
    {
        return new self($angle, $stops, false);
    }

    public function toData(): array
    {
        if ($this->solid) {
            return ['solid' => $this->stops[0][0]->toHex()];
        }

        return [
            'angle' => (int) round($this->angle),
            'stops' => array_map(fn ($s) => [$s[0]->toHex(), (int) round($s[1])], $this->stops),
        ];
    }

    /**
     * The CSS value. A solid fill is still written as an image, so a
     * component's `background: var(--grad-…)` works either way.
     */
    public function toCss(): string
    {
        if ($this->solid) {
            $hex = $this->stops[0][0]->toHex();

            return "linear-gradient({$hex}, {$hex})";
        }
        $stops = implode(', ', array_map(fn ($s) => $s[0]->toHex().' '.round($s[1], 2).'%', $this->stops));

        return 'linear-gradient('.round($this->angle, 2)."deg, {$stops})";
    }

    /**
     * Every colour the gradient shows, sampled along the gradient line with
     * its position (0–100). Every point of an element maps to a point on
     * that line (or beyond it, where the end colours continue), so these
     * samples cover the whole background, not only the stops.
     *
     * @return list<array{0: Color, 1: float}>
     */
    public function samples(int $perSegment = 48): array
    {
        $out = [];
        for ($i = 0; $i < count($this->stops) - 1; $i++) {
            [$a, $from] = $this->stops[$i];
            [$b, $to] = $this->stops[$i + 1];
            for ($k = 0; $k <= $perSegment; $k++) {
                $out[] = [Color::mix($a, $b, $k / $perSegment), $from + ($to - $from) * $k / $perSegment];
            }
        }

        return $out;
    }
}
