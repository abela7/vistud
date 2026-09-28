{{--
    The reusable action bar for bulk operations across all lists (DESIGN.md §5).
    Desktop/tablet: sticky at the top of the list.
    Phone: fixed at the bottom above the tab bar.
--}}
<div class="selection-bar-wrap" x-show="isSelecting" x-cloak>
    <div class="selection-bar" role="toolbar" aria-label="Selection">
        <div class="selection-bar-leading">
            <label class="checkbox-box selection-all-box" title="Select all shown items">
                <input type="checkbox"
                    class="checkbox"
                    :checked="allSelected()"
                    :indeterminate="someSelected() && !allSelected()"
                    x-on:click="toggleAll()"
                    aria-label="Select all shown items">
                <x-icon name="check" class="checkbox-mark size-3.5" stroke-width="3" />
            </label>
            <div class="selection-summary">
                <span class="selection-count" role="status" aria-live="polite">
                    <span x-text="count"></span> selected
                </span>
                <span class="selection-divider" aria-hidden="true">·</span>
                <button type="button" class="selection-link" x-on:click="toggleAll()" x-text="allSelected() ? 'Select none' : 'Select all'"></button>
                <span class="selection-divider" aria-hidden="true">·</span>
                <button type="button" class="selection-link" x-on:click="clearSelection()" :disabled="count === 0">Clear</button>
            </div>
        </div>

        <div class="selection-bar-actions">
            {{ $slot }}
        </div>

        <div class="selection-bar-trailing">
            <button type="button" class="btn btn-sm btn-secondary" x-on:click="exitMode()">Done</button>
        </div>
    </div>
</div>
