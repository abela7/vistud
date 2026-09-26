{{--
    A Lucide icon from resources/icons, inlined and drawn with currentColor
    (ADR 0003 §6.2). Decorative by default; pass a label to make it
    meaningful to screen readers. 20 px unless a size-* class is given.
    Add icons with `npm run icons`.
--}}
@props(['name', 'label' => null])
@php
    static $icons = [];
    if (preg_match('/^[a-z0-9-]+$/', $name) !== 1 || ! is_file($path = resource_path("icons/{$name}.svg"))) {
        throw new InvalidArgumentException("Unknown icon {$name}.");
    }
    // [root attributes, inner markup], without the licence comment, class and fixed size.
    $icons[$name] ??= (function () use ($path) {
        preg_match('/<svg\b([^>]*)>(.*)<\/svg>/s', file_get_contents($path), $m);
        $root = preg_replace('/\s(?:class|width|height)="[^"]*"/', '', $m[1]);
        preg_match_all('/([\w:-]+)="([^"]*)"/', $root, $pairs, PREG_SET_ORDER);

        return [array_column($pairs, 2, 1), trim($m[2])];
    })();
    [$root, $inner] = $icons[$name];
    $sized = str_contains((string) $attributes->get('class'), 'size-');
    $bag = $attributes->class(['shrink-0', 'size-5' => ! $sized])->merge($label === null
        ? ['aria-hidden' => 'true', 'focusable' => 'false']
        : ['role' => 'img', 'aria-label' => $label]);
    $root = array_diff_key($root, $bag->getAttributes());
@endphp
<svg @foreach ($root as $key => $value){{ $key }}="{{ $value }}" @endforeach{{ $bag }}>{!! $inner !!}</svg>
