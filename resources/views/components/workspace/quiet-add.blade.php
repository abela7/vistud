{{--
    A quiet "+ Add …" button that opens its small form where it stands (the plan's adders), so a list isn't
    cluttered with empty boxes. The slot holds the form's fields; Add submits `submit` (a Livewire call); Esc closes
    it and the first field gets the cursor. The form stays open after adding, for the next one.
--}}
@props(['label', 'submit', 'icon' => 'plus'])
<div x-data="{ open: false }" x-on:keydown.escape="open = false" {{ $attributes->class('quiet-add') }}>
    <button type="button" class="quiet-add-button" x-show="! open" x-on:click="open = true; $nextTick(() => $refs.fields.querySelector('input:not([type=checkbox]):not([type=date])')?.focus())">
        <x-icon :name="$icon" class="size-4" />{{ $label }}
    </button>
    <form wire:submit="{{ $submit }}" novalidate class="plan-add" x-show="open" x-cloak x-ref="fields">
        {{ $slot }}
        <button type="submit" class="btn btn-secondary btn-sm">Add</button>
        <button type="button" class="btn btn-ghost btn-sm" x-on:click="open = false">Close</button>
    </form>
</div>
