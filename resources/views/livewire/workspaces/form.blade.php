{{--
    The workspace dialog (App\Livewire\Workspaces\Form). Opened by a
    `workspace-form-open` browser event, or straight away with ?new=1.
    Picking a colour recolours the icon choices in the browser, without a
    request.
--}}
<div>
    <dialog id="workspace-form" class="modal" aria-labelledby="workspace-form-title"
        wire:ignore.self
        x-data
        x-init="@if ($openOnLoad) $el.showModal() @endif"
        x-on:workspace-form-open.window="$el.open || $el.showModal()"
        x-on:close="$wire.cancel()"
        x-on:click="$event.target === $el && $el.close()">
        <form wire:submit="save" novalidate class="modal-panel" x-data="{ colour: $wire.entangle('colour') }">
            <div class="modal-head">
                <div class="min-w-0 flex-1 space-y-1">
                    <h2 id="workspace-form-title" class="text-lg font-semibold">{{ $workspaceId ? 'Edit course' : 'New course' }}</h2>
                </div>
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                    <x-icon name="x" />
                </button>
            </div>

            <div class="space-y-5 px-5 pt-3">
                <x-field name="name" label="Name" wire:model="name" maxlength="80" autocomplete="off" hint="For example Biology, Mathematics or Spanish." autofocus />

                <fieldset class="space-y-2">
                    <legend class="field-label">Colour</legend>
                    <div class="flex flex-wrap gap-2.5">
                        @foreach ($colours as $option)
                            <label class="swatch ws-colour-{{ $option }}" title="{{ ucfirst($option) }}">
                                <input type="radio" class="sr-only" name="colour" value="{{ $option }}" wire:model="colour">
                                <x-icon name="check" class="size-4" />
                                <span class="sr-only">{{ ucfirst($option) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('colour')<p class="field-error">{{ $message }}</p>@enderror
                </fieldset>

                <fieldset class="space-y-2">
                    <legend class="field-label">Icon</legend>
                    <div class="flex flex-wrap gap-2 ws-colour-{{ $colour }}" :class="'ws-colour-' + colour">
                        @foreach ($icons as $option)
                            <label class="icon-option" title="{{ ucfirst(str_replace('-', ' ', $option)) }}">
                                <input type="radio" class="sr-only" name="icon" value="{{ $option }}" wire:model="icon">
                                <x-icon :name="$option" class="size-5" />
                                <span class="sr-only">{{ ucfirst(str_replace('-', ' ', $option)) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('icon')<p class="field-error">{{ $message }}</p>@enderror
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="code" label="Course code (optional)" wire:model="code" maxlength="20" autocomplete="off" />
                    <x-field name="term" label="Term (optional)" wire:model="term" maxlength="40" autocomplete="off" hint="Like Autumn 2026." />
                    <x-field name="startsOn" label="Starts (optional)" type="date" wire:model="startsOn" />
                    <x-field name="endsOn" label="Ends (optional)" type="date" wire:model="endsOn" />
                </div>
            </div>

            <div class="modal-actions">
                @if ($workspaceId)
                    <div class="flex flex-wrap items-center gap-2 sm:mr-auto">
                        @if ($archived)
                            <x-button icon="archive-restore" wire:click="restore" wire:loading.attr="aria-busy" wire:target="restore" busy-label="Restoring…">Restore course</x-button>
                        @else
                            <x-button variant="ghost" icon="archive" wire:click="archive" wire:loading.attr="aria-busy" wire:target="archive" busy-label="Archiving…">Archive</x-button>
                        @endif
                        <x-button variant="danger" icon="trash-2" wire:click="delete" wire:confirm="Are you sure you want to delete this course? All its contents will be permanently deleted." wire:loading.attr="aria-busy" wire:target="delete" busy-label="Deleting…">Delete</x-button>
                    </div>
                @endif
                <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">{{ $workspaceId ? 'Save changes' : 'Create course' }}</x-button>
            </div>
        </form>
    </dialog>
</div>
