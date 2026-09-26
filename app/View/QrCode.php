<?php

namespace App\View;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * A QR code as an SVG drawn only with currentColor, so the theme decides its
 * colours (ADR 0003 §6). Fortify's own QR helper hard-codes colours, which
 * the theme rules forbid. Wrap it in a `.qr-plate`, which gives it the
 * theme's dark-on-light QR colours and the quiet zone scanners need.
 */
final class QrCode
{
    public static function svg(string $content, string $label): string
    {
        $matrix = Encoder::encode($content, ErrorCorrectionLevel::M())->getMatrix();
        $size = $matrix->getWidth();

        // One path: a unit square per dark module.
        $path = '';
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $path .= "M{$x} {$y}h1v1h-1z";
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" role="img" aria-label="%2$s" fill="currentColor" shape-rendering="crispEdges"><path d="%3$s"/></svg>',
            $size,
            e($label),
            $path,
        );
    }
}
