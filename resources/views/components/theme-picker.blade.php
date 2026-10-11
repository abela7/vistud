{{--
    Which theme light mode uses (DESIGN.md §3.6, §3.7). Each preview is drawn
    by that theme's own tokens (data-theme on the preview only), so it holds no
    colours; the choice is kept in this browser (resources/js/appearance.js).
--}}
@php
    $themes = app(\App\Appearance\Themes::class)->ofScheme('light');
    $default = config('vistud.appearance.light');
@endphp
<fieldset {{ $attributes->class('space-y-2') }}>
    <legend class="field-label">Light theme</legend>
    <p class="field-hint">Used when the appearance is light.</p>
    <div class="theme-options">
        @foreach ($themes as $theme)
            <label class="theme-option">
                <input type="radio" name="light-theme" value="{{ $theme->id }}" class="sr-only" data-light-theme-option @checked($theme->id === $default)>
                <span class="theme-swatch" data-theme="{{ $theme->id }}" aria-hidden="true">
                    <span class="theme-swatch-band surface-brand"></span>
                    <span class="theme-swatch-dots">
                        <span class="bg-accent"></span>
                        <span class="bg-surface-sunken"></span>
                        <span class="bg-fg"></span>
                    </span>
                </span>
                <span class="theme-option-name">{{ $theme->name }}</span>
            </label>
        @endforeach
    </div>
</fieldset>
