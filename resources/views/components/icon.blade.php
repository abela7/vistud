{{--
    A Lucide icon from resources/icons, drawn with currentColor (ADR 0003
    §6.2). The drawing comes once from the sprite the browser keeps
    (/icons.svg, App\Appearance\Icons); the page only names it. Decorative by
    default; pass a label to make it meaningful to screen readers. 20 px
    unless a size-* class is given. Add icons with `npm run icons`.
--}}
@props(['name', 'label' => null])
@php
    $href = \App\Appearance\Icons::url($name);
    $sized = str_contains((string) $attributes->get('class'), 'size-');
    $bag = $attributes->class(['shrink-0', 'size-5' => ! $sized])->merge($label === null
        ? ['aria-hidden' => 'true', 'focusable' => 'false']
        : ['role' => 'img', 'aria-label' => $label]);
@endphp
{{-- fill="none" here too: without it the outer <svg> would compute the browser's default black. --}}
<svg fill="none" {{ $bag }}><use href="{{ $href }}"></use></svg>
