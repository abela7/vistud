{{--
    System, light or dark (DESIGN.md §3.6). Changing it swaps the theme in
    place: no reload, no component change (resources/js/appearance.js).
--}}
<fieldset {{ $attributes->class('segmented') }}>
    <legend class="sr-only">Appearance</legend>
    @foreach (['system' => ['System', 'monitor'], 'light' => ['Light', 'sun'], 'dark' => ['Dark', 'moon']] as $mode => [$label, $icon])
        <label class="segmented-option">
            <input type="radio" name="appearance" value="{{ $mode }}" class="sr-only" data-appearance-option @checked($mode === 'system')>
            <x-icon :name="$icon" class="size-4" />
            <span>{{ $label }}</span>
        </label>
    @endforeach
</fieldset>
