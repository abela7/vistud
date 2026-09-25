<?php

namespace Tests\Unit\Appearance;

use App\Appearance\Theme;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ThemeTest extends TestCase
{
    public function test_a_theme_writes_every_token_as_a_custom_property(): void
    {
        $theme = $this->theme();
        $css = $theme->toCss('[data-theme="vistud-light"]');

        $this->assertStringStartsWith("[data-theme=\"vistud-light\"] {\n  color-scheme: light;\n", $css);
        foreach (Theme::COLORS as $token) {
            $this->assertStringContainsString("  --{$token}: #", $css);
        }
        foreach (Theme::GRADIENTS as $token) {
            $this->assertStringContainsString("  --grad-{$token}: linear-gradient(", $css);
        }
    }

    public function test_only_allowlisted_tokens_are_accepted(): void
    {
        $data = $this->data();
        $data['colors']['brand-new'] = '#000000';
        $this->assertRejected($data, 'unknown brand-new');

        $data = $this->data();
        unset($data['gradients']['header']);
        $this->assertRejected($data, 'missing header');
    }

    public function test_values_are_data_never_css(): void
    {
        $data = $this->data();
        $data['colors']['text'] = '#000000; } body { background: url(x)';
        $this->assertRejected($data, 'Theme vistud-light, text');

        $data = $this->data();
        $data['id'] = 'x"] { } [a';
        $this->assertRejected($data, 'A theme ID');
    }

    public function test_gradient_stops_must_be_opaque(): void
    {
        $data = $this->data();
        $data['gradients']['brand'] = ['angle' => 135, 'stops' => [['#00327280', 0], ['#006e91', 100]]];

        $this->assertRejected($data, 'translucent stop');
    }

    public function test_a_gradient_can_be_replaced_by_a_solid_fill(): void
    {
        $data = $this->data();
        $data['gradients']['primary'] = ['solid' => '#006e91'];

        $css = Theme::fromData($data)->toCss(':root');

        $this->assertStringContainsString('--grad-primary: linear-gradient(#006e91, #006e91);', $css);
    }

    private function assertRejected(array $data, string $message): void
    {
        try {
            Theme::fromData($data);
            $this->fail('The theme was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function theme(): Theme
    {
        return Theme::fromData($this->data());
    }

    private function data(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../../resources/themes/vistud-light.json'), true);
    }
}
