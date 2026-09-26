<?php

namespace App\Appearance;

use InvalidArgumentException;

/**
 * A theme: every colour and gradient the interface may use, as data
 * (ADR 0003 §6). Components never contain colour values; they use the
 * CSS variables this class writes. Only allowlisted token names are
 * accepted, and every value is parsed and re-serialised, so a theme can
 * never carry arbitrary CSS (ADR 0003 §6.4).
 *
 * The token list is the contract in DESIGN.md §3 and ADR 0003 §6.1. The
 * category, editor and chart groups join it with the M2 screens that use
 * them.
 */
final readonly class Theme
{
    /** Colour tokens, as `#rrggbb` or `#rrggbbaa`. */
    public const COLORS = [
        // Surfaces
        'bg', 'surface', 'surface-raised', 'surface-sunken', 'overlay',
        // Text
        'text', 'text-muted', 'text-subtle', 'text-disabled', 'text-on-accent',
        // Borders
        'border', 'border-strong', 'divider',
        // Accent
        'accent', 'accent-hover', 'accent-active', 'accent-subtle', 'accent-contrast',
        // Interaction overlays
        'hover', 'pressed', 'selected', 'selection', 'drag-target', 'drop-indicator',
        // Focus
        'focus-ring', 'focus-ring-offset',
        // Status
        'danger', 'danger-subtle', 'danger-on',
        'warning', 'warning-subtle', 'warning-on',
        'success', 'success-subtle', 'success-on',
        'info', 'info-subtle', 'info-on',
        // Foregrounds on gradients (text, icons and focus rings)
        'on-brand', 'on-brand-muted',
        'on-header', 'on-header-muted',
        'on-featured', 'on-featured-muted',
        'on-primary', 'on-selected',
        // Interaction overlays on the brand and header gradients
        'brand-hover', 'brand-pressed',
        'header-hover', 'header-pressed', 'header-selected',
        // Other
        'shadow-color', 'role-admin', 'role-admin-on', 'logo-plate',
        // QR codes: dark modules on a light plate in every theme, so phone
        // scanners can read them (DESIGN.md §3.4)
        'qr-dark', 'qr-light',
    ];

    /** Gradient tokens: an angle and 2–6 opaque stops, or a solid fill. */
    public const GRADIENTS = [
        'brand', 'header', 'featured', 'surface',
        'primary', 'primary-hover', 'primary-active',
        'selected',
    ];

    public const SCHEMES = ['light', 'dark'];

    /**
     * @param  array<string, Color>  $colors  keyed by token name
     * @param  array<string, Gradient>  $gradients  keyed by token name
     */
    private function __construct(
        public string $id,
        public string $name,
        public string $scheme,
        public array $colors,
        public array $gradients,
    ) {}

    public static function fromData(array $data): self
    {
        $id = $data['id'] ?? null;
        if (! is_string($id) || preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) !== 1) {
            throw new InvalidArgumentException('A theme ID is 1–40 lowercase letters, digits and hyphens.');
        }
        $name = $data['name'] ?? null;
        if (! is_string($name) || $name === '' || mb_strlen($name) > 60) {
            throw new InvalidArgumentException("Theme {$id}: a name is 1–60 characters.");
        }
        $scheme = $data['scheme'] ?? null;
        if (! in_array($scheme, self::SCHEMES, true)) {
            throw new InvalidArgumentException("Theme {$id}: the scheme is light or dark.");
        }

        $colors = self::exactly(self::COLORS, $data['colors'] ?? null, "Theme {$id} colours");
        $gradients = self::exactly(self::GRADIENTS, $data['gradients'] ?? null, "Theme {$id} gradients");

        $parsedColors = [];
        foreach (self::COLORS as $token) {
            $parsedColors[$token] = self::parse($id, $token, fn () => Color::hex($colors[$token]));
        }
        $parsedGradients = [];
        foreach (self::GRADIENTS as $token) {
            $gradient = self::parse($id, $token, fn () => Gradient::fromData((array) $gradients[$token]));
            foreach ($gradient->stops as [$color]) {
                if ($color->alpha < 1.0) {
                    // Opaque stops keep the contrast check exact: what's
                    // behind a gradient never shows through it.
                    throw new InvalidArgumentException("Theme {$id}: gradient {$token} has a translucent stop.");
                }
            }
            $parsedGradients[$token] = $gradient;
        }

        return new self($id, $name, $scheme, $parsedColors, $parsedGradients);
    }

    public static function load(string $path): self
    {
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        return self::fromData($data);
    }

    public function color(string $token): Color
    {
        return $this->colors[$token] ?? throw new InvalidArgumentException("Unknown colour token {$token}.");
    }

    public function gradient(string $token): Gradient
    {
        return $this->gradients[$token] ?? throw new InvalidArgumentException("Unknown gradient token {$token}.");
    }

    /** A copy with some values replaced; used to suggest fixes and in tests. */
    public function with(array $colors = [], array $gradients = []): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->scheme,
            array_replace($this->colors, $colors),
            array_replace($this->gradients, $gradients),
        );
    }

    public function toData(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scheme' => $this->scheme,
            'colors' => array_map(fn (Color $c) => $c->toHex(), $this->colors),
            'gradients' => array_map(fn (Gradient $g) => $g->toData(), $this->gradients),
        ];
    }

    /**
     * The theme as CSS custom properties. Colours become `--{token}`,
     * gradients `--grad-{token}`. The values are re-serialised from parsed
     * data, never copied from input.
     */
    public function toCss(string $selector): string
    {
        $lines = ["{$selector} {", "  color-scheme: {$this->scheme};"];
        foreach ($this->colors as $token => $color) {
            $lines[] = "  --{$token}: {$color->toHex()};";
        }
        foreach ($this->gradients as $token => $gradient) {
            $lines[] = "  --grad-{$token}: {$gradient->toCss()};";
        }
        $lines[] = '}';

        return implode("\n", $lines)."\n";
    }

    /** @return array<string, mixed> */
    private static function exactly(array $tokens, mixed $values, string $what): array
    {
        if (! is_array($values)) {
            throw new InvalidArgumentException("{$what} are missing.");
        }
        $missing = array_diff($tokens, array_keys($values));
        $unknown = array_diff(array_keys($values), $tokens);
        if ($missing !== []) {
            throw new InvalidArgumentException("{$what}: missing ".implode(', ', $missing).'.');
        }
        if ($unknown !== []) {
            throw new InvalidArgumentException("{$what}: unknown ".implode(', ', $unknown).'.');
        }

        return $values;
    }

    private static function parse(string $id, string $token, callable $parse): mixed
    {
        try {
            return $parse();
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException("Theme {$id}, {$token}: {$e->getMessage()}", previous: $e);
        }
    }
}
