{{--
    The ViStud logo (DESIGN.md §2.3). The artwork is never recoloured.
    - surface (default): full colour on the theme's --logo-plate, which is
      clear on light themes and a light plate on dark ones;
    - knockout: the white version, for brand gradients only.
    `mark` shows the V alone, for small spaces. By default the 96 px tall
    copies are used (enough for a logo up to 32 px tall on a 3× screen, or
    48 px on a 2× one); `large` uses the full-size artwork, for the watermark.
--}}
@props(['variant' => 'surface', 'mark' => false, 'large' => false, 'alt' => 'ViStud'])
@php
    $name = ($mark ? 'vistud-mark' : 'vistud-logo').($variant === 'knockout' ? '-white' : '');
    $file = $large ? "{$name}.png" : "{$name}-96.png";
    [$width, $height] = match (true) {
        $large && $mark => [402, 397],
        $large => [1219, 397],
        $mark => [97, 96],
        default => [295, 96],
    };
@endphp
@if ($variant === 'knockout')
    <img src="{{ asset("brand/{$file}") }}" alt="{{ $alt }}" width="{{ $width }}" height="{{ $height }}" {{ $attributes->class('w-auto') }}>
@else
    <span class="logo-plate">
        <img src="{{ asset("brand/{$file}") }}" alt="{{ $alt }}" width="{{ $width }}" height="{{ $height }}" {{ $attributes->class('w-auto') }}>
    </span>
@endif
