<?php

/*
| Derives the ViStud logo assets from the owner's original
| (resources/brand/vistud-logo-original.png), which is kept byte-for-byte.
|
| The original is an RGB PNG with no alpha channel: the grey checkerboard
| behind the logo is painted into the pixels, not transparency. This script
| removes it and writes transparent assets to public/brand/:
|
|   vistud-logo.png          full colour, for light surfaces and the logo plate
|   vistud-logo-white.png    single-colour white, for brand gradients
|   vistud-mark.png          the V-and-leaves mark alone, full colour
|   vistud-mark-white.png    the mark alone, white
|
| and display-size copies of them, so pages never download the full-size
| artwork to show it 32–40 px tall (DESIGN.md §2.1). The large white mark
| stays the watermark's source: at 16 KB it is already small.
|
|   vistud-logo-96.png, vistud-logo-white-96.png     96 px tall (3× a 32 px logo)
|   vistud-mark-96.png, vistud-mark-white-96.png     96 px tall
|   favicon-32.png                                   32 × 32, the mark
|   apple-touch-icon-180.png                         180 × 180, the mark on white
|
| Method: the checkerboard is a regular 12 px grid of #222221 and #131212,
| so the background behind every pixel is known exactly. Interior logo
| pixels keep their colour at full opacity. Each edge pixel takes the colour
| of the nearest interior pixel, with the opacity that best explains its
| value as a mix of that colour and the known background. The thin light
| halo around the V in the original (left over from an earlier cut-out) is
| discarded this way. No colour is invented or retouched.
|
| Run: php resources/brand/clean-logo.php
*/

$source = __DIR__.'/vistud-logo-original.png';
$out = dirname(__DIR__, 2).'/public/brand';

$im = imagecreatefrompng($source);
[$w, $h] = [imagesx($im), imagesy($im)];
$rgb = function (int $x, int $y) use ($im): array {
    $c = imagecolorat($im, $x, $y);

    return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
};

// The painted checkerboard: 12 px cells, offset (6, 11), light when the cell indices sum to odd.
$background = fn (int $x, int $y): array => ((intdiv($x + 6, 12) + intdiv($y + 11, 12)) % 2 === 1) ? [0x22, 0x22, 0x21] : [0x13, 0x12, 0x12];

$isLogo = function (array $p): bool {
    $max = max($p);
    $min = min($p);

    return $max >= 60 && ($max - $min) / $max >= 0.6;
};

// Interior: a logo pixel whose 5x5 neighbourhood is all logo.
$logo = [];
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $logo[$y][$x] = $isLogo($rgb($x, $y));
    }
}
$interior = [];
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $solid = $logo[$y][$x];
        for ($dy = -2; $solid && $dy <= 2; $dy++) {
            for ($dx = -2; $solid && $dx <= 2; $dx++) {
                $solid = $logo[$y + $dy][$x + $dx] ?? false;
            }
        }
        $interior[$y][$x] = $solid;
    }
}

$nearestInterior = function (int $x, int $y) use ($interior, $rgb, $w, $h): ?array {
    for ($r = 1; $r <= 6; $r++) {
        for ($dy = -$r; $dy <= $r; $dy++) {
            for ($dx = -$r; $dx <= $r; $dx++) {
                if (max(abs($dx), abs($dy)) !== $r) {
                    continue;
                }
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $yy >= 0 && $xx < $w && $yy < $h && $interior[$yy][$xx]) {
                    return $rgb($xx, $yy);
                }
            }
        }
    }

    return null;
};

$colour = imagecreatetruecolor($w, $h);
$white = imagecreatetruecolor($w, $h);
foreach ([$colour, $white] as $canvas) {
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
}

$bounds = [$w, $h, 0, 0];
for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        if ($interior[$y][$x]) {
            [$fg, $alpha] = [$rgb($x, $y), 1.0];
        } else {
            $fg = $nearestInterior($x, $y);
            if ($fg === null) {
                continue;
            }
            $bg = $background($x, $y);
            $p = $rgb($x, $y);
            $d = array_map(fn ($a, $b) => $a - $b, $fg, $bg);
            $len = array_sum(array_map(fn ($v) => $v * $v, $d));
            $alpha = $len === 0 ? 0 : array_sum(array_map(fn ($pi, $bi, $di) => ($pi - $bi) * $di, $p, $bg, $d)) / $len;
            $alpha = max(0.0, min(1.0, $alpha));
            if ($alpha < 0.04) {
                continue;
            }
        }
        $a = (int) round(127 * (1 - $alpha));
        imagesetpixel($colour, $x, $y, imagecolorallocatealpha($colour, $fg[0], $fg[1], $fg[2], $a));
        imagesetpixel($white, $x, $y, imagecolorallocatealpha($white, 255, 255, 255, $a));
        $bounds = [min($bounds[0], $x), min($bounds[1], $y), max($bounds[2], $x), max($bounds[3], $y)];
    }
}

// Trim to the logo, with a small margin.
$crop = function ($canvas, int $x1, int $y1, int $x2, int $y2) {
    $m = 4;
    [$x1, $y1] = [max(0, $x1 - $m), max(0, $y1 - $m)];
    $cw = min(imagesx($canvas), $x2 + $m + 1) - $x1;
    $ch = min(imagesy($canvas), $y2 + $m + 1) - $y1;
    $trimmed = imagecreatetruecolor($cw, $ch);
    imagealphablending($trimmed, false);
    imagesavealpha($trimmed, true);
    imagecopy($trimmed, $canvas, 0, 0, $x1, $y1, $cw, $ch);

    return $trimmed;
};

// The mark ends before the wordmark's "v" starts (x ≈ 440).
$markRight = 0;
for ($x = 0; $x < 440; $x++) {
    for ($y = 0; $y < $h; $y++) {
        if ($logo[$y][$x]) {
            $markRight = $x;
            break;
        }
    }
}

[$x1, $y1, $x2, $y2] = $bounds;
imagepng($crop($colour, $x1, $y1, $x2, $y2), "{$out}/vistud-logo.png", 9);
imagepng($crop($white, $x1, $y1, $x2, $y2), "{$out}/vistud-logo-white.png", 9);
imagepng($crop($colour, $x1, $y1, $markRight, $y2), "{$out}/vistud-mark.png", 9);
imagepng($crop($white, $x1, $y1, $markRight, $y2), "{$out}/vistud-mark-white.png", 9);

// Display sizes, resampled from the full-size files just written.
$scaled = function (string $file, int $height, string $to) use ($out) {
    $source = imagecreatefrompng("{$out}/{$file}");
    $width = (int) round(imagesx($source) * $height / imagesy($source));
    $target = imagecreatetruecolor($width, $height);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
    imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
    imagepng($target, "{$out}/{$to}", 9);
};
$scaled('vistud-logo.png', 96, 'vistud-logo-96.png');
$scaled('vistud-logo-white.png', 96, 'vistud-logo-white-96.png');
$scaled('vistud-mark.png', 96, 'vistud-mark-96.png');
$scaled('vistud-mark-white.png', 96, 'vistud-mark-white-96.png');

// Square icons: the full-colour mark centred, transparent for the favicon and
// on white for Apple's home-screen icon (iOS fills transparency with black).
$icon = function (int $size, float $fill, ?array $background, string $to) use ($out) {
    $mark = imagecreatefrompng("{$out}/vistud-mark.png");
    $target = imagecreatetruecolor($size, $size);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    imagefill($target, 0, 0, $background === null
        ? imagecolorallocatealpha($target, 0, 0, 0, 127)
        : imagecolorallocate($target, ...$background));
    imagealphablending($target, $background !== null);
    $scale = $size * $fill / max(imagesx($mark), imagesy($mark));
    [$w, $h] = [(int) round(imagesx($mark) * $scale), (int) round(imagesy($mark) * $scale)];
    imagecopyresampled($target, $mark, intdiv($size - $w, 2), intdiv($size - $h, 2), 0, 0, $w, $h, imagesx($mark), imagesy($mark));
    imagepng($target, "{$out}/{$to}", 9);
};
$icon(32, 1.0, null, 'favicon-32.png');
$icon(180, 0.72, [255, 255, 255], 'apple-touch-icon-180.png');

echo "Wrote public/brand/ (logo {$x1},{$y1} to {$x2},{$y2}; mark to x {$markRight}).\n";
