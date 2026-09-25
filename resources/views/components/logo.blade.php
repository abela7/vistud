{{--
    The ViStud logo (DESIGN.md §2.3). The artwork is never recoloured.
    - surface (default): full colour on the theme's --logo-plate, which is
      clear on light themes and a light plate on dark ones;
    - knockout: the white version, for brand gradients only.
    `mark` shows the V alone, for small spaces.
--}}
@props(['variant' => 'surface', 'mark' => false, 'alt' => 'ViStud'])
@php
    $file = ($mark ? 'vistud-mark' : 'vistud-logo').($variant === 'knockout' ? '-white' : '').'.png';
    [$width, $height] = $mark ? [402, 397] : [1219, 397];
@endphp
@if ($variant === 'knockout')
    <img src="{{ asset("brand/{$file}") }}" alt="{{ $alt }}" width="{{ $width }}" height="{{ $height }}" {{ $attributes->class('w-auto') }}>
@else
    <span class="logo-plate">
        <img src="{{ asset("brand/{$file}") }}" alt="{{ $alt }}" width="{{ $width }}" height="{{ $height }}" {{ $attributes->class('w-auto') }}>
    </span>
@endif
