<?php

namespace App\Appearance;

/**
 * Contrast checks every theme must pass before it is saved or shipped
 * (ADR 0003 §6.4, DESIGN.md §3.4). Text and controls on a gradient are
 * checked against every colour the gradient shows, not only its stops,
 * and translucent overlays are composited over each of those colours.
 */
final class ThemeValidator
{
    public const TEXT = 4.5;

    public const UI = 3.0;

    /** QR codes need strong contrast, dark on light, to scan reliably. */
    public const QR = 7.0;

    private const STATUSES = ['danger', 'warning', 'success', 'info'];

    /**
     * @return list<array{pair: string, minimum: float, actual: float, at: string, suggestion: string}>
     */
    public function failures(Theme $theme): array
    {
        $failures = [];
        foreach ($this->pairs($theme) as [$foreground, $background, $minimum]) {
            [$actual, $at] = $this->worst($theme, $foreground, $background);
            if ($actual + 1e-9 < $minimum) {
                $failures[] = [
                    'pair' => "{$foreground} on {$background}",
                    'minimum' => $minimum,
                    'actual' => round($actual, 2),
                    'at' => $at,
                    'suggestion' => $this->suggest($theme, $foreground, $background, $minimum),
                ];
            }
        }

        // Scanners expect dark modules on a light background, never inverted.
        if ($theme->color('qr-dark')->luminance() >= $theme->color('qr-light')->luminance()) {
            $failures[] = [
                'pair' => 'qr-dark on qr-light',
                'minimum' => self::QR,
                'actual' => round(Color::contrast($theme->color('qr-dark'), $theme->color('qr-light')), 2),
                'at' => 'qr-light',
                'suggestion' => 'qr-dark must be the darker of the two: scanners read dark modules on a light plate.',
            ];
        }

        return $failures;
    }

    /**
     * Every checked pair, as [foreground, background, minimum]. A background
     * is a colour token, `grad:{token}` for a gradient, or `{overlay}>{background}`
     * for a translucent overlay composited over another background. A
     * foreground is a colour token, or `logo` for the logo artwork's colours.
     *
     * @return list<array{0: string, 1: string, 2: float}>
     */
    public function pairs(Theme $theme): array
    {
        $pairs = [];
        $add = function (array $foregrounds, array $backgrounds, float $minimum) use (&$pairs) {
            foreach ($foregrounds as $fg) {
                foreach ($backgrounds as $bg) {
                    $pairs[] = [$fg, $bg, $minimum];
                }
            }
        };
        $surfaces = ['bg', 'surface', 'surface-raised', 'surface-sunken'];

        // Text on calm surfaces, and on the interaction overlays over them.
        $add(['text', 'text-muted'], [...$surfaces, 'grad:surface'], self::TEXT);
        $add(['text-subtle'], ['bg', 'surface'], self::TEXT);
        $add(['text'], ['hover>surface', 'pressed>surface', 'selected>surface', 'selection>surface', 'drag-target>surface'], self::TEXT);

        // Accent.
        $add(['text-on-accent'], ['accent', 'accent-hover', 'accent-active'], self::TEXT);
        $add(['accent-contrast'], ['bg', 'surface', 'accent-subtle'], self::TEXT);
        $add(['text'], ['accent-subtle'], self::TEXT);

        // Status colours are used as text (field errors), as fills with
        // their -on colour, and as subtle alert backgrounds.
        foreach (self::STATUSES as $status) {
            $add([$status], ['bg', 'surface', "{$status}-subtle"], self::TEXT);
            $add(["{$status}-on"], [$status], self::TEXT);
            $add(['text'], ["{$status}-subtle"], self::TEXT);
        }
        $add(['role-admin-on'], ['role-admin'], self::TEXT);

        // Gradients: every foreground against every colour the gradient shows.
        $add(['on-brand', 'on-brand-muted'], ['grad:brand'], self::TEXT);
        $add(['on-brand'], ['brand-hover>grad:brand', 'brand-pressed>grad:brand'], self::TEXT);
        $add(['on-header', 'on-header-muted'], ['grad:header'], self::TEXT);
        $add(['on-header'], ['header-hover>grad:header', 'header-pressed>grad:header', 'header-selected>grad:header'], self::TEXT);
        $add(['on-featured', 'on-featured-muted'], ['grad:featured'], self::TEXT);
        $add(['on-primary'], ['grad:primary', 'grad:primary-hover', 'grad:primary-active'], self::TEXT);
        $add(['on-selected'], ['grad:selected'], self::TEXT);

        // Controls, focus and graphics: 3:1 (WCAG 1.4.11).
        $add(['focus-ring'], [...$surfaces, 'grad:surface', 'focus-ring-offset'], self::UI);
        $add(['border-strong'], ['bg', 'surface'], self::UI);
        $add(['accent', 'role-admin'], ['bg', 'surface'], self::UI);
        $add(['drop-indicator'], ['surface'], self::UI);
        $add(['logo'], ['logo-plate>bg', 'logo-plate>surface'], self::UI);
        $add(['qr-dark'], ['qr-light'], self::QR);

        return $pairs;
    }

    /**
     * The lowest contrast of a pair and where it occurs.
     *
     * @return array{0: float, 1: string}
     */
    public function worst(Theme $theme, string $foreground, string $background): array
    {
        $foregrounds = $foreground === 'logo'
            ? array_map(fn ($hex) => [Color::hex($hex), $hex], Brand::logoColors())
            : [[$theme->color($foreground), $foreground]];

        $worst = [INF, ''];
        foreach ($foregrounds as [$fg, $fgLabel]) {
            foreach ($this->background($theme, $background) as [$bg, $bgLabel]) {
                $ratio = Color::contrast($fg, $bg);
                if ($ratio < $worst[0]) {
                    $worst = [$ratio, $foreground === 'logo' ? "{$fgLabel} on {$bgLabel}" : $bgLabel];
                }
            }
        }

        return $worst;
    }

    /**
     * Every opaque colour a background shows, with a label saying where.
     *
     * @return list<array{0: Color, 1: string}>
     */
    private function background(Theme $theme, string $background): array
    {
        if (str_contains($background, '>')) {
            [$overlay, $under] = explode('>', $background, 2);
            $color = $theme->color($overlay);

            return array_map(
                fn ($sample) => [$color->over($sample[0]), $sample[1]],
                $this->background($theme, $under),
            );
        }
        if (str_starts_with($background, 'grad:')) {
            $token = substr($background, 5);

            return array_map(
                fn ($sample) => [$sample[0], sprintf('%s at %d%%', $background, round($sample[1]))],
                $theme->gradient($token)->samples(),
            );
        }
        $color = $theme->color($background);
        if ($color->alpha < 1.0) {
            // A translucent base sits on the page background.
            $color = $color->over($theme->color('bg'));
        }

        return [[$color, $background]];
    }

    /**
     * A fix: the nearest lightness for the foreground (or, for the logo, the
     * plate behind it) that passes every pair using it, keeping its hue,
     * chroma and opacity.
     */
    private function suggest(Theme $theme, string $foreground, string $background, float $minimum): string
    {
        $token = $foreground === 'logo' ? 'logo-plate' : $foreground;
        $related = array_values(array_filter(
            $this->pairs($theme),
            fn ($pair) => $pair[0] === $foreground || str_starts_with($pair[1], "{$token}>") || $pair[1] === $token,
        ));
        $current = $theme->color($token);
        [$l, $c, $h] = $current->toOklch();

        foreach (range(1, 100) as $step) {
            foreach ([$l - $step / 100, $l + $step / 100] as $candidateL) {
                if ($candidateL < 0 || $candidateL > 1) {
                    continue;
                }
                $candidate = Color::oklch($candidateL, $c, $h, $current->alpha);
                $trial = $theme->with([$token => $candidate]);
                foreach ($related as [$fg, $bg, $min]) {
                    if ($this->worst($trial, $fg, $bg)[0] < $min) {
                        continue 2;
                    }
                }

                return "Try {$candidate->toHex()} for {$token}, or adjust the background.";
            }
        }

        return "No lightness of {$token} passes; adjust the background or use a supporting surface.";
    }
}
