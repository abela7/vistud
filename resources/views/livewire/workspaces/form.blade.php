{{--
    The workspace dialog (App\Livewire\Workspaces\Form). Opened by a
    `workspace-form-open` browser event.
    The colour and icon are picked as on the New course page (x-workspace.look):
    a theme colour or one of the student's own, and the icon from many.
--}}
<div>
    <dialog id="workspace-form" class="modal" aria-labelledby="workspace-form-title"
        wire:ignore.self
        x-data
        x-on:workspace-form-open.window="$el.open || $el.showModal()"
        x-on:close="$wire.cancel()"
        x-on:click="$event.target === $el && $el.close()">
        <form wire:submit="save" novalidate class="modal-panel" x-data="{ colour: $wire.entangle('colour') }">
            <div class="modal-head">
                <div class="min-w-0 flex-1 space-y-1">
                    <h2 id="workspace-form-title" class="text-lg font-semibold">Edit course</h2>
                </div>
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                    <x-icon name="x" />
                </button>
            </div>

            <div class="space-y-5 px-5 pt-3">
                <x-field name="name" label="Name" wire:model="name" maxlength="80" autocomplete="off" hint="For example Biology, Mathematics or Spanish." autofocus />

                <x-workspace.look :colour="$colour" :icon="$icon" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field name="code" label="Course code (optional)" wire:model="code" maxlength="20" autocomplete="off" />
                    <x-field name="term" label="Term (optional)" wire:model="term" maxlength="40" autocomplete="off" hint="Like Autumn 2026." />
                    <x-field name="startsOn" label="Starts (optional)" type="date" wire:model="startsOn" />
                    <x-field name="endsOn" label="Ends (optional)" type="date" wire:model="endsOn" />
                </div>

                <x-checkbox name="projectTools" id="workspace-project-tools" wire:model="projectTools" label="Project tools for assignments"
                    hint="Labels, a team, priorities, milestones and how an assignment is going, for a big or group one." />
            </div>

            <div class="modal-actions">
                @if ($workspaceId)
                    {{-- Deleting asks in place (no browser confirm): Delete shows what it does, then "Delete for good" or "Keep it". --}}
                    <div class="flex flex-wrap items-center gap-2 sm:mr-auto" x-data="{ sure: false }" x-init="$el.closest('dialog')?.addEventListener('close', () => sure = false)">
                        <template x-if="! sure">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($archived)
                                    <x-button icon="archive-restore" wire:click="restore" wire:loading.attr="aria-busy" wire:target="restore" busy-label="Restoring…">Restore course</x-button>
                                @else
                                    <x-button variant="ghost" icon="archive" wire:click="archive" wire:loading.attr="aria-busy" wire:target="archive" busy-label="Archiving…">Archive</x-button>
                                @endif
                                <x-button variant="danger" icon="trash-2" x-on:click="sure = true">Delete</x-button>
                            </div>
                        </template>
                        <div class="flex flex-wrap items-center gap-2" x-show="sure" x-cloak role="alert">
                            <p class="text-sm text-fg-muted">Everything in this course is deleted for good.</p>
                            <x-button variant="danger" icon="trash-2" wire:click="delete" wire:loading.attr="aria-busy" wire:target="delete" busy-label="Deleting…">Delete for good</x-button>
                            <x-button variant="ghost" x-on:click="sure = false">Keep it</x-button>
                        </div>
                    </div>
                @endif
                <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save changes</x-button>
            </div>
        </form>
    </dialog>
</div>
