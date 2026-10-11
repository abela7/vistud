{{--
    The theme (DESIGN.md §3.6, §3.7): a light and a dark theme together; the mode (system, light
    or dark) picks between them. Each preview is drawn by that theme's own tokens (data-theme on the preview
    only), so it holds no colours; the choice is kept in this browser (resources/js/appearance.js).
--}}
@php
    $themes = app(\App\Appearance\Themes::class)->pairs(config('vistud.appearance.themes'));
    $default = array_key_first(array_filter($themes, fn (array $pair) => $pair['light']->id === config('vistud.appearance.light')));
@endphp
<fieldset {{ $attributes->class('space-y-2') }}>
    <legend class="field-label">Theme</legend>
    <p class="field-hint">Each has a light and a dark look.</p>
    <div class="theme-options">
        @foreach ($themes as $id => $pair)
            <label class="theme-option">
                <input type="radio" name="theme" value="{{ $id }}" class="sr-only" data-theme-option @checked($id === $default)>
                <span class="theme-swatches" aria-hidden="true">
                    @foreach ([$pair['light'], $pair['dark']] as $look)
                        <span class="theme-swatch" data-theme="{{ $look->id }}">
                            <span class="theme-swatch-band surface-brand"></span>
                            <span class="theme-swatch-dots">
                                <span class="bg-accent"></span>
                                <span class="bg-fg"></span>
                            </span>
                        </span>
                    @endforeach
                </span>
                <span class="theme-option-name">{{ $pair['name'] }}</span>
            </label>
        @endforeach
    </div>
</fieldset>
