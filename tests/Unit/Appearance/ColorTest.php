<?php

namespace Tests\Unit\Appearance;

use App\Appearance\Color;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ColorTest extends TestCase
{
    public function test_contrast_follows_wcag(): void
    {
        $this->assertEqualsWithDelta(21.0, Color::contrast(Color::hex('#000000'), Color::hex('#ffffff')), 0.001);
        $this->assertEqualsWithDelta(1.0, Color::contrast(Color::hex('#006e91'), Color::hex('#006e91')), 0.001);
        // The logo's colours against white (DESIGN.md §2).
        $this->assertEqualsWithDelta(12.36, Color::contrast(Color::hex('#ffffff'), Color::hex('#003272')), 0.01);
        $this->assertEqualsWithDelta(3.40, Color::contrast(Color::hex('#ffffff'), Color::hex('#009b9e')), 0.01);
    }

    public function test_a_translucent_foreground_is_composited_before_measuring(): void
    {
        $half = Color::hex('#ffffff80');
        $black = Color::hex('#000000');

        $this->assertSame('#808080', $half->over($black)->toHex());
        $this->assertEqualsWithDelta(
            Color::contrast(Color::hex('#808080'), $black),
            Color::contrast($half, $black),
            0.01,
        );
    }

    public function test_hex_round_trips_and_oklch_round_trips(): void
    {
        foreach (['#003272', '#006e91', '#009b9e', '#ffffff', '#000000', '#a3294f'] as $hex) {
            $this->assertSame($hex, Color::hex($hex)->toHex());
            $this->assertSame($hex, Color::oklch(...Color::hex($hex)->toOklch())->toHex());
        }
        $this->assertSame('#0b1b2e99', Color::hex('#0B1B2E99')->toHex());
    }

    public function test_out_of_gamut_oklch_is_brought_into_srgb(): void
    {
        $color = Color::oklch(0.5, 0.4, 200);

        foreach ([$color->r, $color->g, $color->b] as $channel) {
            $this->assertGreaterThanOrEqual(0, round($channel));
            $this->assertLessThanOrEqual(255, round($channel));
        }
    }

    public function test_only_hex_colours_are_accepted(): void
    {
        foreach (['red', '#fff', 'rgb(0,0,0)', '#12345', '#0000000', 'url(x)', '#00000g'] as $bad) {
            try {
                Color::hex($bad);
                $this->fail("{$bad} was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
