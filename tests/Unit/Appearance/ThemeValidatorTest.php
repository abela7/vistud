<?php

namespace Tests\Unit\Appearance;

use App\Appearance\Color;
use App\Appearance\Gradient;
use App\Appearance\Theme;
use App\Appearance\ThemeValidator;
use PHPUnit\Framework\TestCase;

/**
 * ADR 0003 §6.4: built-in themes and presets must pass the same contrast
 * check as custom themes, enforced here. DESIGN.md §3.4: text on a
 * gradient is checked across the whole gradient.
 */
class ThemeValidatorTest extends TestCase
{
    public function test_every_built_in_theme_passes(): void
    {
        $paths = glob(__DIR__.'/../../../resources/themes/*.json');
        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $theme = Theme::load($path);
            $this->assertSame([], (new ThemeValidator)->failures($theme), "{$theme->id} fails contrast.");
        }
    }

    public function test_text_is_checked_across_the_gradient_not_only_at_its_ends(): void
    {
        // Black passes on both ends (5.3:1 on red, 8.7:1 on sky blue), but
        // sRGB interpolation darkens the middle to about 3.6:1.
        $theme = $this->theme()->with(
            colors: ['on-featured' => Color::hex('#000000'), 'on-featured-muted' => Color::hex('#000000')],
            gradients: ['featured' => Gradient::fromData(['angle' => 90, 'stops' => [['#ff0000', 0], ['#00b0ff', 100]]])],
        );
        $this->assertGreaterThan(4.5, Color::contrast(Color::hex('#000000'), Color::hex('#ff0000')));
        $this->assertGreaterThan(4.5, Color::contrast(Color::hex('#000000'), Color::hex('#00b0ff')));

        $failure = $this->failure($theme, 'on-featured on grad:featured');

        $this->assertLessThan(4.0, $failure['actual']);
        $this->assertMatchesRegularExpression('/^grad:featured at \d+%$/', $failure['at']);
        $this->assertNotContains($failure['at'], ['grad:featured at 0%', 'grad:featured at 100%']);
    }

    public function test_translucent_foregrounds_and_overlays_are_composited(): void
    {
        $theme = $this->theme()->with(colors: ['on-brand-muted' => Color::hex('#ffffff80')]);
        $this->failure($theme, 'on-brand-muted on grad:brand');

        $theme = $this->theme()->with(colors: ['brand-hover' => Color::hex('#ffffff80')]);
        $this->failure($theme, 'on-brand on brand-hover>grad:brand');
    }

    public function test_a_failure_says_what_would_pass(): void
    {
        $theme = $this->theme()->with(colors: ['text-muted' => Color::hex('#a0a8b0')]);
        $failure = $this->failure($theme, 'text-muted on bg');

        $this->assertMatchesRegularExpression('/^Try (#[0-9a-f]{6}) for text-muted/', $failure['suggestion']);
        preg_match('/#[0-9a-f]{6}/', $failure['suggestion'], $m);
        $fixed = $theme->with(colors: ['text-muted' => Color::hex($m[0])]);
        $this->assertSame([], (new ThemeValidator)->failures($fixed));
    }

    public function test_the_logo_must_stay_legible_on_its_plate(): void
    {
        $dark = Theme::load(__DIR__.'/../../../resources/themes/vistud-dark.json');
        $withoutPlate = $dark->with(colors: ['logo-plate' => Color::hex('#00000000')]);

        $failure = $this->failure($withoutPlate, 'logo on logo-plate>bg');

        $this->assertStringContainsString('#003272', $failure['at']);
    }

    public function test_focus_rings_and_strong_borders_need_three_to_one(): void
    {
        $theme = $this->theme()->with(colors: ['focus-ring' => Color::hex('#c8d4e0'), 'border-strong' => Color::hex('#c8d4e0')]);

        $this->failure($theme, 'focus-ring on surface');
        $this->failure($theme, 'border-strong on surface');
    }

    /** @return array{pair: string, minimum: float, actual: float, at: string, suggestion: string} */
    private function failure(Theme $theme, string $pair): array
    {
        foreach ((new ThemeValidator)->failures($theme) as $failure) {
            if ($failure['pair'] === $pair) {
                $this->addToAssertionCount(1);

                return $failure;
            }
        }
        $this->fail("Expected {$pair} to fail.");
    }

    private function theme(): Theme
    {
        return Theme::load(__DIR__.'/../../../resources/themes/vistud-light.json');
    }
}
