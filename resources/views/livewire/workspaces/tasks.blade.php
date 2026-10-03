{{--
    A workspace's assignments and tasks, on its Overview (App\Livewire\Workspaces\Tasks): Coming up, a box with a head
    (its icon, its name, how many are late, and small buttons for the calendar, selecting several and adding one),
    holding the soonest first in groups: Late, the next 7 days, then later (the owner's review, 2026-10-05: one long
    list was too much to take in). At most six show; "All assignments" has the rest. The circle on a row marks it
    done; its ⋯ starts it, edits or deletes it.
--}}
@php
    use App\Study\Activities;
    use Illuminate\Support\Str;

    $buckets = ['late' => [], 'soon' => [], 'later' => []];
    foreach ($open as $task) {
        $buckets[match (true) {
            $task->overdue() => 'late',
            $task->dueOn !== null && $task->dueAt()->lessThan(now()->addDays(7)) => 'soon',
            default => 'later',
        }][] = $task;
    }
    $names = ['late' => 'Late', 'soon' => 'Next 7 days', 'later' => 'Later'];
    $room = 6;
    $groups = [];
    foreach ($buckets as $key => $list) {
        $take = array_slice($list, 0, $room);
        $room -= count($take);
        if ($take !== []) {
            $groups[$key] = $take;
        }
    }
    $hidden = count($open) - array_sum(array_map('count', $groups));
@endphp
<section aria-labelledby="tasks-heading" class="ov-panel" x-data="selectable()" :class="{ 'is-selecting': isSelecting, 'is-selecting-container': isSelecting }" x-on:keydown.window="handleKeydown($event)" x-on:selection-clear.window="clearSelection()">
    <div class="ov-head">
        <span class="item-icon" aria-hidden="true"><x-icon name="calendar-clock" class="size-5" /></span>
        <h2 id="tasks-heading" class="panel-title">
            Coming up
            @if ($buckets['late'] !== [])
                <span class="coming-late"><x-icon name="circle-alert" class="size-3.5" />{{ count($buckets['late']) }} late</span>
            @endif
        </h2>
        <div class="coming-actions">
            <a href="{{ route('workspaces.show', [$workspaceId, 'calendar']) }}" class="topbar-button size-8" title="Calendar"><x-icon name="calendar" class="size-4" /><span class="sr-only">Calendar</span></a>
            @if ($open !== [] || $done !== [])
                <button type="button" class="topbar-button size-8" x-on:click="toggleMode()" :aria-pressed="isSelecting ? 'true' : 'false'" title="Select several"><x-icon name="list-checks" class="size-4" /><span class="sr-only">Select</span></button>
            @endif
            <button type="button" class="topbar-button size-8" wire:click="newTask" title="Add an assignment or task"><x-icon name="plus" class="size-4" /><span class="sr-only">Add a task</span></button>
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
        <div class="empty-place">
            <span class="item-icon" aria-hidden="true"><x-icon name="calendar-clock" class="size-5" /></span>
            <p class="font-medium">Nothing due</p>
            <p class="text-sm text-fg-muted">Add assignments, quizzes and exams, and the soonest shows here.</p>
            <button type="button" class="btn btn-secondary" wire:click="newTask"><x-icon name="plus" class="size-4" />Add an assignment</button>
        </div>
    @else
        <div role="group" aria-label="To do">
            @forelse ($groups as $key => $list)
                <div @class(['coming-group', "is-{$key}"])>
                    <h3 class="coming-group-name">{{ $names[$key] }}<span class="coming-group-count">{{ count($buckets[$key]) }}</span></h3>
                    <ul class="item-list coming-list" role="list" aria-label="{{ $names[$key] }}">
                        @foreach ($list as $task)
                            @include('livewire.workspaces.partials.task-row')
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="coming-clear"><x-icon name="circle-check" class="size-5" />All done.</p>
            @endforelse
        </div>
        @if ($showDone && $done !== [])
            <div class="coming-group is-done">
                <h3 class="coming-group-name">Done<span class="coming-group-count">{{ count($done) }}</span></h3>
                <ul id="done-tasks" class="item-list coming-list" role="list" aria-label="Done">
                    @foreach ($done as $task)
                        @include('livewire.workspaces.partials.task-row')
                    @endforeach
                </ul>
            </div>
        @endif
        <div class="coming-foot">
            @if ($done !== [])
                <button type="button" class="quiet-link" wire:click="toggleDone" aria-expanded="{{ $showDone ? 'true' : 'false' }}" aria-controls="done-tasks">
                    {{ $showDone ? 'Hide' : 'Show' }} {{ count($done) }} done
                </button>
            @endif
            <a href="{{ route('workspaces.show', [$workspaceId, 'assignments']) }}" class="quiet-link coming-all">{{ $hidden > 0 ? "{$hidden} more · " : '' }}All assignments<x-icon name="chevron-right" class="size-4" /></a>
        </div>
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
