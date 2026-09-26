{{--
    A row's actions menu. $id makes the menu's ID; $label names the row;
    $items: [label, icon, wire:click, disabled]. Livewire leaves the open or
    closed state alone (wire:ignore.self), so an update can't snap it shut;
    shell.js closes it on an outside click, Esc, or choosing an item.
--}}
<div class="relative shrink-0">
    <button type="button" class="topbar-button" wire:ignore.self data-menu-button aria-controls="menu-{{ $id }}" aria-expanded="false" title="Actions">
        <x-icon name="ellipsis" class="size-5" />
        {{-- Text, not an attribute: the button's own attributes are ignored by updates, its content isn't. --}}
        <span class="sr-only">Actions for {{ $label }}</span>
    </button>
    <div id="menu-{{ $id }}" class="row-menu" wire:ignore.self data-menu-panel hidden>
        @foreach ($items as [$text, $icon, $action, $disabled])
            <button type="button" class="menu-item" wire:click="{{ $action }}" @disabled($disabled)>
                <x-icon :name="$icon" class="size-4" />{{ $text }}
            </button>
        @endforeach
    </div>
</div>
