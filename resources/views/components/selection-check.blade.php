{{-- Per-row/tile selection checkbox for bulk actions (DESIGN.md §5). --}}
@props(['key', 'label'])
<span class="selection-check" x-show="isSelecting" x-cloak>
    <label class="checkbox-box" title="{{ $label }}" x-on:click.prevent.stop="toggle('{{ $key }}', $event.shiftKey)">
        <input type="checkbox"
            class="checkbox selection-checkbox"
            :checked="isSelected('{{ $key }}')"
            x-on:keydown.space.prevent.stop="toggle('{{ $key }}')"
            aria-label="{{ $label }}">
        <x-icon name="check" class="checkbox-mark size-3.5" stroke-width="3" />
    </label>
</span>
