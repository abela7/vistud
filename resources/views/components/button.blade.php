{{--
    Buttons (DESIGN.md §5.1). Variants: primary (the theme's primary-action
    gradient), secondary, ghost, danger. `busy-label` gives the text shown
    while the button's form is submitting.
--}}
@props(['variant' => 'secondary', 'size' => 'md', 'type' => 'button', 'icon' => null, 'busyLabel' => null])
<button type="{{ $type }}" {{ $attributes->class(['btn', "btn-{$variant}", 'btn-lg' => $size === 'lg']) }}>
    <span class="btn-idle">
        @if ($icon)
            <x-icon :name="$icon" class="size-4" />
        @endif
        {{ $slot }}
    </span>
    @if ($busyLabel)
        <span class="btn-busy" role="status">
            <x-icon name="loader-circle" class="size-4 animate-spin" />
            {{ $busyLabel }}
        </span>
    @endif
</button>
