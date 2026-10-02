{{--
    A workspace's assignments and tasks, on its Overview
    (App\Livewire\Workspaces\Tasks). The round button marks one done or not;
    "Start it" puts it in progress.
--}}
@php
    use App\Study\Activities;
    use Illuminate\Support\Str;
@endphp
<section aria-labelledby="tasks-heading" class="overview-card space-y-3" x-data="selectable()" :class="{ 'is-selecting': isSelecting, 'is-selecting-container': isSelecting }" x-on:keydown.window="handleKeydown($event)" x-on:selection-clear.window="clearSelection()">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 id="tasks-heading" class="font-semibold">Coming up</h2>
        <a href="{{ route('workspaces.show', [$workspaceId, 'assignments']) }}" class="mr-auto text-sm font-medium text-fg-muted hover:text-fg">All assignments</a>
        <div class="flex flex-wrap items-center gap-2">
            @if ($open !== [] || $done !== [])
                <button type="button" class="btn btn-sm btn-secondary" x-on:click="toggleMode()" :aria-pressed="isSelecting ? 'true' : 'false'">
                    <x-icon name="list-checks" class="size-4" />
                    <span class="max-sm:sr-only" x-text="isSelecting ? 'Done' : 'Select'">Select</span>
                </button>
            @endif
            <x-button variant="ghost" icon="plus" wire:click="newTask" aria-label="Add a task">Add</x-button>
        </div>
    </div>

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" />
    </div>

    <x-selection-bar>
        <x-button variant="secondary" size="sm" icon="circle-check" ::disabled="count === 0" x-on:click="$wire.bulk('status', selectedKeys(), { status: 'done' })">Mark done</x-button>
        <x-button variant="danger" size="sm" icon="trash-2" ::disabled="count === 0" x-on:click="$wire.openBulkDelete(selectedKeys())">Delete</x-button>
    </x-selection-bar>

    @if ($open === [] && $done === [])
        <p class="text-sm text-fg-muted">Nothing due. Add assignments, quizzes and exams here.</p>
    @else
        <ul class="divide-y divide-divider" role="list" aria-label="To do">
            @forelse ($open as $task)
                @include('livewire.workspaces.partials.task-row')
            @empty
                <li class="py-2 text-sm text-fg-muted">All done.</li>
            @endforelse
        </ul>
        @if ($done !== [])
            <x-button variant="ghost" wire:click="toggleDone" aria-expanded="{{ $showDone ? 'true' : 'false' }}" aria-controls="done-tasks">
                {{ $showDone ? 'Hide' : 'Show' }} {{ count($done) }} done
            </x-button>
            @if ($showDone)
                <ul id="done-tasks" class="divide-y divide-divider" role="list" aria-label="Done">
                    @foreach ($done as $task)
                        @include('livewire.workspaces.partials.task-row')
                    @endforeach
                </ul>
            @endif
        @endif
    @endif

    <dialog id="tasks-dialog" class="modal" aria-labelledby="tasks-dialog-title"
        wire:ignore.self
        x-data
        x-on:tasks-dialog-open.window="$el.open || $el.showModal()"
        x-on:tasks-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.mode && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($mode)
            <form wire:submit="save" novalidate class="modal-panel" wire:key="tasks-dialog-{{ $mode }}-{{ $targetId }}">
                <div class="modal-head">
                    <h2 id="tasks-dialog-title" class="min-w-0 flex-1 text-lg font-semibold break-words" @if ($mode === 'delete') tabindex="-1" autofocus @endif>
                        {{ match (true) { $mode === 'delete' => 'Delete “'.$target.'”?', $targetId === null => 'New assignment or task', default => 'Edit “'.$target.'”' } }}
                    </h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>

                <div class="space-y-4 px-5 pt-2">
                    @if ($mode === 'task')
                        <x-field name="title" label="What" wire:model="title" maxlength="200" autocomplete="off" hint="Like “ER diagram for the library” or “Revise joins”." autofocus />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="field">
                                <label for="task-kind" class="field-label">Kind</label>
                                <select id="task-kind" class="input" wire:model="kind">
                                    @foreach (Activities::KINDS as $value => $word)
                                        <option value="{{ $value }}">{{ $word }}</option>
                                    @endforeach
                                </select>
                                @error('kind') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <x-field name="dueOn" label="Due (optional)" type="date" wire:model="dueOn" />
                        </div>
                        <x-field name="dueTime" label="At (optional)" type="time" wire:model="dueTime" hint="Without a time, it's due at the end of the day." />
                        @if ($modules !== [])
                            <div class="field">
                                <label for="task-module" class="field-label">Module (optional)</label>
                                <select id="task-module" class="input" wire:model="moduleId">
                                    <option value="">Not in a module</option>
                                    @foreach ($modules as $module)
                                        <option value="{{ $module->id }}">{{ $module->title }}</option>
                                    @endforeach
                                </select>
                                @error('moduleId') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    @else
                        <p class="text-fg-muted">It leaves your list. This can't be undone.</p>
                    @endif
                </div>

                <div class="modal-actions">
                    <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                    <x-button type="submit" :variant="$mode === 'delete' ? 'danger' : 'primary'" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">
                        {{ match (true) { $mode === 'delete' => 'Delete', $targetId === null => 'Add', default => 'Save' } }}
                    </x-button>
                </div>
            </form>
        @endif
    </dialog>

    <dialog id="bulk-task-dialog" class="modal" aria-labelledby="bulk-task-dialog-title"
        wire:ignore.self
        x-data
        x-on:bulk-task-dialog-open.window="$el.open || $el.showModal()"
        x-on:bulk-task-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.bulkKeys = []"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel" role="document">
            <div class="modal-head">
                <h2 id="bulk-task-dialog-title" class="min-w-0 flex-1 text-lg font-semibold">Delete tasks?</h2>
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                    <x-icon name="x" />
                </button>
            </div>
            <div class="space-y-4 px-5 pt-2">
                <p class="text-sm text-fg-muted">
                    Delete {{ count($bulkKeys) }} selected {{ Str::plural('task', count($bulkKeys)) }}? They leave your list and this can't be undone.
                </p>
            </div>
            <div class="modal-actions">
                <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                <x-button variant="danger" wire:click="confirmBulkDelete" wire:loading.attr="aria-busy" busy-label="Deleting…">Delete</x-button>
            </div>
        </div>
    </dialog>
</section>
