{{-- A workspace's icon on its colour. Decorative: the name is always shown or labelled beside it. --}}
@props(['workspace', 'size' => 'md'])
@php
    [$box, $icon] = ['sm' => ['size-6', 'size-3.5'], 'md' => ['', 'size-4'], 'lg' => ['size-11', 'size-5']][$size];
@endphp
<span {{ $attributes->class(['ws-chip', "ws-colour-{$workspace->colour}", $box]) }} aria-hidden="true"><x-icon :name="$workspace->icon" :class="$icon" /></span>
