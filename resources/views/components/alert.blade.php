{{--
    An inline message (DESIGN.md §5.3). The tone is carried by an icon and
    a title as well as colour. Danger and warning are announced at once.
--}}
@props(['tone' => 'info', 'title' => null])
@php
    $icon = ['danger' => 'circle-alert', 'warning' => 'triangle-alert', 'success' => 'circle-check', 'info' => 'info'][$tone];
    $role = in_array($tone, ['danger', 'warning'], true) ? 'alert' : 'status';
@endphp
<div role="{{ $role }}" {{ $attributes->class(['alert', "alert-{$tone}"]) }}>
    <x-icon :name="$icon" class="alert-icon mt-0.5 size-5" />
    <div class="space-y-0.5">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div>{{ $slot }}</div>
    </div>
</div>
