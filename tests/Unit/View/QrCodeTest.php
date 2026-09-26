<?php

namespace Tests\Unit\View;

use App\View\QrCode;
use Tests\TestCase;

class QrCodeTest extends TestCase
{
    public function test_it_draws_only_with_current_color(): void
    {
        $svg = QrCode::svg('otpauth://totp/ViStud:ada@example.test?secret=JBSWY3DPEHPK3PXP&issuer=ViStud', 'QR code');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertSame(1, substr_count($svg, 'fill="currentColor"'));
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b|rgb\(/i', $svg);
        $this->assertMatchesRegularExpression('/viewBox="0 0 (\d+) \1"/', $svg);
    }

    public function test_the_label_is_escaped(): void
    {
        $svg = QrCode::svg('x', '"><script>');

        $this->assertStringNotContainsString('<script>', $svg);
    }
}
