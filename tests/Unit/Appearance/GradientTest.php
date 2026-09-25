<?php

namespace Tests\Unit\Appearance;

use App\Appearance\Color;
use App\Appearance\Gradient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GradientTest extends TestCase
{
    public function test_a_gradient_is_written_with_its_angle_and_stop_positions(): void
    {
        $gradient = Gradient::fromData(['angle' => 135, 'stops' => [['#003272', 0], ['#006e91', 60], ['#007378', 100]]]);

        $this->assertSame('linear-gradient(135deg, #003272 0%, #006e91 60%, #007378 100%)', $gradient->toCss());
        $this->assertSame(['angle' => 135, 'stops' => [['#003272', 0], ['#006e91', 60], ['#007378', 100]]], $gradient->toData());
    }

    public function test_a_solid_fill_is_still_an_image_so_components_need_no_change(): void
    {
        $solid = Gradient::fromData(['solid' => '#a3294f']);

        $this->assertSame('linear-gradient(#a3294f, #a3294f)', $solid->toCss());
        $this->assertSame(['solid' => '#a3294f'], $solid->toData());
    }

    public function test_samples_cover_the_whole_gradient_line_not_only_the_stops(): void
    {
        $gradient = Gradient::fromData(['angle' => 90, 'stops' => [['#000000', 0], ['#ffffff', 100]]]);
        $samples = $gradient->samples(4);

        $this->assertSame([0.0, 25.0, 50.0, 75.0, 100.0], array_map(fn ($s) => $s[1], $samples));
        $this->assertSame('#808080', $samples[2][0]->toHex());
    }

    public function test_invalid_gradients_are_rejected(): void
    {
        $bad = [
            ['angle' => 400, 'stops' => [['#000000', 0], ['#ffffff', 100]]],
            ['angle' => '90deg', 'stops' => [['#000000', 0], ['#ffffff', 100]]],
            ['angle' => 90, 'stops' => [['#000000', 0]]],
            ['angle' => 90, 'stops' => [['#000000', 60], ['#ffffff', 40]]],
            ['angle' => 90, 'stops' => [['#000000', 0], ['#ffffff', 120]]],
            ['angle' => 90, 'stops' => [['#000000', '0%'], ['#ffffff', 100]]],
            ['angle' => 90, 'stops' => [['red', 0], ['#ffffff', 100]]],
            ['angle' => 90, 'stops' => [['#000000', 0], ['#ffffff', 100]], 'repeat' => true],
            ['solid' => '#000000', 'angle' => 90],
            ['solid' => 'transparent'],
        ];
        foreach ($bad as $data) {
            try {
                Gradient::fromData($data);
                $this->fail('Accepted '.json_encode($data));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_css_output_comes_from_parsed_values_only(): void
    {
        $gradient = Gradient::linear(45.5, [[Color::hex('#000000'), 0.0], [Color::hex('#ffffff'), 100.0]]);

        $this->assertSame('linear-gradient(45.5deg, #000000 0%, #ffffff 100%)', $gradient->toCss());
    }
}
