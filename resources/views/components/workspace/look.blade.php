{{--
    How a course looks, picked in the New course page and the Edit course dialog: its colour (one of the theme's, or one of
    the student's own from the browser's colour picker, which starts from the colour picked before) and its icon (the first
    ones, then More icons in groups). Lives inside the form's x-data, which holds `colour` and `icon`; a colour of their own
    is drawn by the server (App\Appearance\CourseColour), so no colour is written here.
--}}
@props(['colour', 'icon'])
@php
    use App\Appearance\CourseColour;
    use App\Appearance\Theme;
    use App\Study\Workspaces;

    $custom = CourseColour::isCustom($colour);
    $first = Workspaces::ICONS;
    $hidden = ! in_array($icon, $first, true);
    $name = fn (string $option) => ucfirst(str_replace('-', ' ', $option));
@endphp
<fieldset class="space-y-2" x-data="{
    {{-- The colour on screen, as the picker's six hex digits: the canvas writes back any colour it is given in that form. --}}
    hexOf(el) {
        const pen = document.createElement('canvas').getContext('2d');
        pen.fillStyle = getComputedStyle(el).backgroundColor;
        return pen.fillStyle.startsWith('#') ? pen.fillStyle : null;
    },
    startFrom(input) {
        if (colour.startsWith('#')) return;
        const chosen = $el.querySelector('.swatch:has(input:checked)');
        const hex = chosen ? hexOf(chosen) : null;
        if (hex) input.value = hex;
    },
}">
    <legend class="field-label">Colour</legend>
    <div class="flex flex-wrap items-center gap-2.5">
        @foreach (Theme::CATEGORIES as $option)
            <label class="swatch ws-colour-{{ $option }}" title="{{ ucfirst($option) }}">
                <input type="radio" class="sr-only" name="colour" value="{{ $option }}" wire:model.live="colour">
                <x-icon name="check" class="size-4" />
                <span class="sr-only">{{ ucfirst($option) }}</span>
            </label>
        @endforeach
        <label @class(['swatch', 'swatch-custom', 'ws-colour-custom' => $custom, 'is-checked' => $custom]) @if ($custom) style="{{ CourseColour::style($colour) }}" @endif title="A colour of your own">
            <input type="color" class="swatch-picker" aria-label="A colour of your own" @if ($custom) value="{{ $colour }}" @endif
                x-on:pointerdown="startFrom($el)" x-on:keydown.enter="startFrom($el)" x-on:keydown.space="startFrom($el)"
                x-on:input.debounce.200ms="colour = $event.target.value; $wire.set('colour', $event.target.value)">
            <x-icon :name="$custom ? 'check' : 'palette'" class="size-4" />
        </label>
    </div>
    @error('colour')<p class="field-error">{{ $message }}</p>@enderror
</fieldset>

<fieldset class="space-y-2">
    <legend class="field-label">Icon</legend>
    <div class="icon-picker {{ CourseColour::classFor($colour) }}" @if ($custom) style="{{ CourseColour::style($colour) }}" @endif>
        <div class="flex flex-wrap gap-2">
            @foreach ($first as $option)
                <label class="icon-option" title="{{ $name($option) }}">
                    <input type="radio" class="sr-only" name="icon" value="{{ $option }}" wire:model="icon" x-on:change="$dispatch('course-icon-picked')">
                    <x-icon :name="$option" class="size-5" />
                    <span class="sr-only">{{ $name($option) }}</span>
                </label>
            @endforeach
        </div>
        <details class="icon-more" @if ($hidden) open @endif>
            <summary class="icon-more-summary"><x-icon name="chevron-right" class="size-4" />More icons</summary>
            @foreach (Workspaces::ICON_GROUPS as $group => $options)
                <div class="icon-group" role="group" aria-label="{{ $group }}">
                    <p class="icon-group-label" aria-hidden="true">{{ $group }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($options as $option)
                            <label class="icon-option" title="{{ $name($option) }}">
                                <input type="radio" class="sr-only" name="icon" value="{{ $option }}" wire:model="icon" x-on:change="$dispatch('course-icon-picked')">
                                <x-icon :name="$option" class="size-5" />
                                <span class="sr-only">{{ $name($option) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </details>
    </div>
    @error('icon')<p class="field-error">{{ $message }}</p>@enderror
</fieldset>
