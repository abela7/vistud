{{-- The one dialog of App\Livewire\Workspaces\Contents: a module, a folder, a move, or deleting. --}}
@php
    $headings = [
        'module' => $creating ? 'New module' : 'Edit module',
        'folder' => $creating ? 'New folder'.($target ? ' in '.$target : '') : 'Rename folder',
        'move' => 'Move “'.$target.'”',
        'delete' => $targetType === 'note' ? 'Delete “'.$target.'” for good?' : 'Delete “'.$target.'”?',
    ];
    $submit = ['module' => $creating ? 'Add module' : 'Save', 'folder' => $creating ? 'Add folder' : 'Rename', 'move' => 'Move', 'delete' => $targetType === 'note' ? 'Delete for good' : 'Delete'];
@endphp
<dialog id="structure-dialog" class="modal" aria-labelledby="structure-dialog-title"
    wire:ignore.self
    x-data
    x-on:structure-dialog-open.window="$el.open || $el.showModal()"
    x-on:structure-dialog-close.window="$el.open && $el.close()"
    x-on:close="$wire.mode && $wire.close()"
    x-on:click="$event.target === $el && $el.close()">
    @if ($mode)
        <form wire:submit="save" novalidate class="modal-panel" wire:key="dialog-{{ $mode }}-{{ $targetId }}-{{ $creating ? 'new' : 'edit' }}">
            <div class="modal-head">
                <h2 id="structure-dialog-title" class="min-w-0 flex-1 text-lg font-semibold break-words" @if (in_array($mode, ['move', 'delete'], true)) tabindex="-1" autofocus @endif>{{ $headings[$mode] }}</h2>
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                    <x-icon name="x" />
                </button>
            </div>

            <div class="space-y-4 px-5 pt-2">
                @if ($mode === 'module')
                    <x-field name="title" label="Title" wire:model="title" maxlength="120" autocomplete="off" hint="Like “Week 1: Cells” or “Chapter 3”." autofocus />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field name="startsOn" label="Starts (optional)" type="date" wire:model="startsOn" />
                        <x-field name="endsOn" label="Ends (optional)" type="date" wire:model="endsOn" />
                    </div>
                @elseif ($mode === 'folder')
                    <x-field name="name" label="Name" wire:model="name" maxlength="120" autocomplete="off" autofocus />
                @elseif ($mode === 'move')
                    <fieldset class="space-y-1">
                        <legend class="field-label mb-2">Move to</legend>
                        @foreach ($moveOptions as $option)
                            <label @class(['move-option', 'is-disabled' => $option['disabled']]) style="--depth: {{ $option['depth'] }}">
                                <input type="radio" name="destination" value="{{ $option['value'] }}" wire:model="destination" @disabled($option['disabled'])>
                                <x-icon :name="$option['icon']" class="size-4 shrink-0 text-fg-muted" />
                                <span class="min-w-0 break-words">{{ $option['label'] }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                @elseif ($targetType === 'note')
                    <p class="text-fg-muted">The note and every saved version of it are deleted. This can't be undone.</p>
                @else
                    <p class="text-fg-muted">This can't be undone. Only an empty {{ $targetType }} can be deleted.</p>
                @endif

                @if ($error)
                    <x-alert tone="danger">{{ $error }}</x-alert>
                @endif
            </div>

            <div class="modal-actions">
                <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                <x-button type="submit" :variant="$mode === 'delete' ? 'danger' : 'primary'" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">{{ $submit[$mode] }}</x-button>
            </div>
        </form>
    @endif
</dialog>
