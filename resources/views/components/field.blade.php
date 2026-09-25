{{--
    A labelled text field with its hint and error (DESIGN.md §5.2). The
    error comes from the validation bag unless one is passed (false shows
    none, for errors the page reports elsewhere). A password
    field can offer a reveal button. The `aside` slot sits at the end of the
    label row (for example, a "Forgot password?" link).
--}}
@props(['name', 'label', 'type' => 'text', 'hint' => null, 'error' => null, 'revealable' => false, 'value' => null])
@php
    $id = $attributes->get('id', "field-{$name}");
    $error = $error === false ? null : ($error ?? ($errors->first($name) ?: null));
    $describedBy = trim(($hint ? "{$id}-hint " : '').($error ? "{$id}-error" : ''));
@endphp
<div class="space-y-1.5">
    <div class="flex items-baseline justify-between gap-4">
        <label for="{{ $id }}" class="field-label">{{ $label }}</label>
        {{ $aside ?? '' }}
    </div>
    <div class="relative">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $type }}"
            @if ($value !== null) value="{{ $value }}" @endif
            @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
            @if ($error) aria-invalid="true" @endif
            {{ $attributes->except('id')->class(['input', 'input-revealable' => $revealable]) }}
        >
        @if ($revealable)
            <button type="button" class="input-reveal" data-password-toggle aria-controls="{{ $id }}" aria-pressed="false"
                aria-label="Show password" title="Show password" data-label-show="Show password" data-label-hide="Hide password">
                <x-icon name="eye" data-icon-show />
                <x-icon name="eye-off" data-icon-hide hidden />
            </button>
        @endif
    </div>
    @if ($hint)
        <p id="{{ $id }}-hint" class="field-hint">{{ $hint }}</p>
    @endif
    @if ($error)
        <p id="{{ $id }}-error" class="field-error">
            <x-icon name="circle-alert" class="mt-0.5 size-4" />
            <span>{{ $error }}</span>
        </p>
    @endif
</div>
