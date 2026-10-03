{{--
    A "+ Add" button with its choices in a small menu, for where a row of buttons would be too much (a section's
    files, an assignment's files and notes). $id makes the menu's ID; $items: [label, icon, Alpine x-on:click], or
    [label, icon, null, Livewire wire:click]. Like partials.row-menu, it opens in the top layer (resources/js/shell.js).
--}}
<div class="relative shrink-0">
    <button type="button" class="btn btn-secondary btn-sm" wire:ignore.self data-menu-button aria-controls="menu-{{ $id }}" aria-expanded="false">
        <x-icon name="plus" class="size-4" />Add
    </button>
    <div id="menu-{{ $id }}" class="row-menu" wire:ignore.self data-menu-panel popover="manual" hidden>
        @foreach ($items as $item)
            @if ($item[2] !== null)
                <button type="button" class="menu-item" x-on:click="{{ $item[2] }}"><x-icon :name="$item[1]" class="size-4" />{{ $item[0] }}</button>
            @else
                <button type="button" class="menu-item" wire:click="{{ $item[3] }}"><x-icon :name="$item[1]" class="size-4" />{{ $item[0] }}</button>
            @endif
        @endforeach
    </div>
</div>
