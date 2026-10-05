{{--
    A workspace's Progress section (App\Livewire\Workspaces\Progress; docs/specs/vistud-2-blueprint.md §3.5.5): the
    course's ring, the filters, and the tree: modules (the one the student is in open, the rest folded) with their bars,
    and under each its topics with where they stand and Study. A topic's name opens the topic sheet. The numbers are
    App\Study\Rollups', the ones the course home shows too.
--}}
@php
    use Illuminate\Support\Str;

    $statusIcons = ['not_started' => 'circle-dot', 'covered' => 'check', 'understood' => 'circle-check', 'confused' => 'circle-alert', 'mastered' => 'shield-check'];
    $filters = ['all' => 'All', 'attention' => 'Needs attention', 'not_started' => 'Not started', 'mastered' => 'Mastered'];
    $nothing = ['attention' => 'Nothing needs another look.', 'not_started' => 'Everything has been started.', 'mastered' => 'Nothing is mastered yet.'];
    $ordered = $roll->groups();
    $allTopics = $roll->topics();
@endphp
<div class="space-y-6" x-data="selectable()" :class="{ 'is-selecting': isSelecting, 'is-selecting-container': isSelecting }" x-on:keydown.window="handleKeydown($event)" x-on:selection-clear.window="clearSelection()">
    <div class="progress-head">
        <div class="progress-summary">
            <div class="ring" role="progressbar" aria-label="Topics understood" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $roll->percent() }}">
                <svg viewBox="0 0 36 36" aria-hidden="true" focusable="false"><circle class="ring-track" cx="18" cy="18" r="15.9155" /><circle class="ring-bar" cx="18" cy="18" r="15.9155" stroke-dasharray="{{ $roll->percent() }} 100" /></svg>
                <span>{{ $roll->percent() }}%</span>
            </div>
            <ul class="progress-facts" role="list">
                <li>{{ $roll->percent() }} % · {{ $roll->done() }} of {{ $roll->total() }} {{ $roll->total() === 1 ? 'topic' : 'topics' }}</li>
                @if ($roll->cards['due'] > 0)
                    <li><a href="{{ route('workspaces.flashcards.review', $workspaceId) }}" class="quiet-link">{{ $roll->cards['due'] }} {{ $roll->cards['due'] === 1 ? 'card' : 'cards' }} due</a></li>
                @endif
                @if ($roll->stuck > 0)
                    <li>{{ $roll->stuck }} stuck {{ $roll->stuck === 1 ? 'question' : 'questions' }}</li>
                @endif
            </ul>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($allTopics !== [])
                <button type="button" class="btn btn-secondary" x-on:click="toggleMode()" :aria-pressed="isSelecting ? 'true' : 'false'">
                    <x-icon name="list-checks" class="size-4" />
                    <span x-text="isSelecting ? 'Done' : 'Select'">Select</span>
                </button>
            @endif
            <a href="{{ route('workspaces.questions.create', [$workspaceId, 'from' => route('workspaces.show', [$workspaceId, 'progress'], false)]) }}" class="btn btn-secondary"><x-icon name="circle-help" class="size-4" />New question</a>
            <x-button variant="primary" icon="plus" wire:click="newTopic">New topic</x-button>
        </div>
    </div>

    <div role="status" aria-live="polite">
        <x-toast :message="$notice" />
    </div>

    <x-selection-bar>
        <x-button variant="secondary" size="sm" icon="check" ::disabled="count === 0" x-on:click="$wire.bulk('status', selectedKeys(), { status: 'covered' })">Covered</x-button>
        <x-button variant="secondary" size="sm" icon="circle-check" ::disabled="count === 0" x-on:click="$wire.bulk('status', selectedKeys(), { status: 'understood' })">Understood</x-button>
        <x-button variant="secondary" size="sm" icon="circle-alert" ::disabled="count === 0" x-on:click="$wire.bulk('status', selectedKeys(), { status: 'confused' })">Still confusing</x-button>
        @if ($modules !== [])
            <x-button variant="secondary" size="sm" icon="folder-input" ::disabled="count === 0" x-on:click="$wire.openBulkMove(selectedKeys())">Move to module…</x-button>
        @endif
        <x-button variant="danger" size="sm" icon="trash-2" ::disabled="count === 0" x-on:click="$wire.openBulkRemove(selectedKeys())">Remove</x-button>
    </x-selection-bar>

    <section aria-labelledby="topics-heading" class="space-y-4">
        <h2 id="topics-heading" class="sr-only">Topics</h2>
        @if ($allTopics === [])
            <div class="empty-place">
                <span class="item-icon" aria-hidden="true"><x-icon name="trending-up" class="size-5" /></span>
                <p class="font-medium">No topics yet</p>
                <x-button variant="primary" icon="plus" wire:click="newTopic">New topic</x-button>
            </div>
        @else
            <div class="segmented segmented-sm" role="group" aria-label="Show topics">
                @foreach ($filters as $key => $word)
                    <button type="button" @class(['segmented-option', 'is-current' => $filter === $key]) wire:click="showOnly('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">{{ $word }}<span class="tabular-nums"> {{ $counts[$key] }}</span></button>
                @endforeach
            </div>

            @php $any = false; @endphp
            @foreach ($ordered as $group)
                @php
                    $shown = array_values(array_filter($group->topics, fn ($topic) => \App\Livewire\Workspaces\Progress::shows($filter, $topic)));
                    // Under their folders: the module's own topics first, then each folder's, in the module's order.
                    $folderOf = fn ($topic) => $topFolders[$topic->topic->folderId ?? ''] ?? null;
                    $place = array_flip(array_map(fn ($topic) => $topic->topic->id, $shown));
                    usort($shown, fn ($a, $b) => [$folderOf($a)['order'] ?? -1, $place[$a->topic->id]] <=> [$folderOf($b)['order'] ?? -1, $place[$b->topic->id]]);
                    $lastFolder = false;
                    $isCurrent = $group->id() !== '' && $group->id() === $roll->currentId;
                    $open = $filter !== 'all' || $isCurrent || ($roll->currentId === null && $loop->first);
                @endphp
                @continue ($group->total() === 0 || $shown === [])
                @php $any = true; @endphp
                <section class="tree-module module-card" wire:key="tree-{{ $group->id() ?: 'none' }}-{{ $filter }}" x-data="{ open: {{ $open ? 'true' : 'false' }} }" aria-label="{{ $group->title() }}">
                    <div class="tree-module-head">
                        <button type="button" class="tree-toggle" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="tree-{{ $group->id() ?: 'none' }}">
                            <span class="tree-chevron" x-bind:class="{ 'is-open': open }" aria-hidden="true"><x-icon name="chevron-right" class="size-4" /></span>
                            @if ($group->number > 0)
                                <span class="module-number" aria-hidden="true">{{ $group->number }}</span>
                            @endif
                            <span class="tree-title">{{ $group->title() }}@if ($isCurrent)<span class="sr-only"> (where you are)</span>@endif</span>
                            @if ($isCurrent)
                                <span class="current-dot" aria-hidden="true" title="Where you are"></span>
                            @endif
                        </button>
                        <span class="tree-numbers">
                            <span class="meter" role="progressbar" aria-label="Topics understood in {{ $group->title() }}" aria-valuemin="0" aria-valuemax="{{ $group->total() }}" aria-valuenow="{{ $group->done() }}"><span style="width: {{ $group->percent() }}%"></span></span>
                            <span class="tabular-nums">{{ $group->done() }}/{{ $group->total() }}</span>
                            @if ($group->tested !== null)
                                <span class="item-meta">tested {{ $group->tested }} %</span>
                            @endif
                            @if ($group->attention() > 0)
                                <span class="status-chip status-confused"><x-icon name="circle-alert" class="size-3.5" />{{ $group->attention() }} to look at</span>
                            @endif
                        </span>
                        @if ($group->module !== null)
                            <a href="{{ route('workspaces.modules.show', [$workspaceId, $group->id()]) }}" class="topbar-button" aria-label="Open {{ $group->title() }}" title="Open the module"><x-icon name="chevron-right" class="size-5" /></a>
                        @endif
                    </div>
                    <ul id="tree-{{ $group->id() ?: 'none' }}" class="divide-y divide-divider" role="list" x-show="open || isSelecting" @unless ($open) x-cloak @endunless>
                        @foreach ($shown as $topic)
                            @php
                                $t = $topic->topic;
                                $siblings = $group->topics;
                                $at = array_search($topic, $siblings, true);
                                $inFolder = $folderOf($topic);
                            @endphp
                            @if ($inFolder !== null && $inFolder['id'] !== $lastFolder)
                                <li class="tree-folder" wire:key="tree-folder-{{ $inFolder['id'] }}-{{ $filter }}">
                                    <x-icon name="folder" class="size-4 shrink-0" /><a href="{{ route('workspaces.folders.show', [$workspaceId, $inFolder['id']]) }}" class="tile-link">{{ $inFolder['name'] }}</a>
                                </li>
                            @endif
                            @php $lastFolder = $inFolder['id'] ?? null; @endphp
                            <li wire:key="topic-{{ $t->id }}" class="topic-row"
                                data-select-key="topic:{{ $t->id }}"
                                :class="{ 'is-selected': isSelected('topic:{{ $t->id }}') }"
                                x-on:click="handleRowClick($event, 'topic:{{ $t->id }}')">
                                <x-selection-check key="topic:{{ $t->id }}" label="Select {{ $t->name }}" />
                                <div class="topic-main">
                                    <button type="button" class="tree-topic-name" x-on:click="isSelecting ? toggle('topic:{{ $t->id }}', $event.shiftKey) : Livewire.dispatch('topic-sheet-open', { topicId: '{{ $t->id }}' })">{{ $t->name }}</button>
                                    <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-fg-muted">
                                        <span @class(['status-chip', "status-{$topic->shown}"]) title="{{ $topic->evidence() }}"><x-icon :name="$statusIcons[$topic->shown]" class="size-3.5" />{{ $topic->word() }}<span class="sr-only">. {{ $topic->evidence() }}</span></span>
                                        @if ($topic->small() !== '')
                                            <span>{{ $topic->small() }}</span>
                                        @endif
                                    </p>
                                    @if ($topic->needsAttention())
                                        <p class="tree-attention"><x-icon name="circle-alert" class="size-4" />{{ ucfirst($topic->attentionWords()) }}</p>
                                    @endif
                                </div>
                                <x-button size="sm" icon="play" wire:click="study('{{ $t->id }}')" aria-label="Study {{ $t->name }}">Study</x-button>
                                @include('livewire.workspaces.partials.row-menu', ['id' => $t->id, 'label' => $t->name, 'items' => [
                                    ['New flashcard', 'gallery-vertical-end', "newFlashcard('{$t->id}')", false],
                                    ['New question about it', 'circle-help', "newQuestion('{$t->id}')", false],
                                    ['Move to module…', 'folder-input', "moveTopic('{$t->id}')", $modules === []],
                                    ['Move up', 'arrow-up', "moveTopicBy('{$t->id}', -1)", $at === 0],
                                    ['Move down', 'arrow-down', "moveTopicBy('{$t->id}', 1)", $at === count($siblings) - 1],
                                    ['Remove', 'trash-2', "retireTopic('{$t->id}')", false],
                                ]])
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
            @unless ($any)
                <p class="text-fg-muted">{{ $nothing[$filter] ?? 'No topics yet.' }}</p>
            @endunless
        @endif
    </section>

    <livewire:workspaces.topic-sheet :workspace-id="$workspaceId" key="progress-topic-sheet" />

    <dialog id="progress-dialog" class="modal" aria-labelledby="progress-dialog-title"
        wire:ignore.self
        x-data
        x-on:progress-dialog-open.window="$el.open || $el.showModal()"
        x-on:progress-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.mode && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($mode)
            <form wire:submit="save" novalidate class="modal-panel" wire:key="dialog-{{ $mode }}-{{ $targetId }}">
                <div class="modal-head">
                    <h2 id="progress-dialog-title" class="min-w-0 flex-1 text-lg font-semibold break-words">{{ $mode === 'topic' ? 'New topic' : 'Move “'.$target.'”' }}</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>

                <div class="space-y-4 px-5 pt-2">
                    @if ($mode === 'topic')
                        <x-field name="name" label="Name" wire:model="name" maxlength="120" autocomplete="off" hint="Like “Joins” or “Normalisation”." autofocus />
                    @endif
                    @if (($mode === 'topic' && $modules !== []) || $mode === 'move')
                        <div class="field">
                            <label for="topic-module" class="field-label">Module</label>
                            <select id="topic-module" class="input" wire:model="moduleId" @if ($mode === 'move') autofocus @endif>
                                <option value="">Not in a module</option>
                                @foreach ($modules as $module)
                                    <option value="{{ $module->id }}">{{ $module->title }}</option>
                                @endforeach
                            </select>
                            @error('moduleId') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                <div class="modal-actions">
                    <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                    <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">{{ $mode === 'topic' ? 'Add topic' : 'Move' }}</x-button>
                </div>
            </form>
        @endif
    </dialog>

    <livewire:workspaces.flashcard-editor :workspace-id="$workspaceId" key="progress-card-editor" />

    <dialog id="bulk-topic-move-dialog" class="modal" aria-labelledby="bulk-topic-move-title"
        wire:ignore.self
        x-data
        x-on:bulk-topic-move-open.window="$el.open || $el.showModal()"
        x-on:bulk-topic-move-close.window="$el.open && $el.close()"
        x-on:close="$wire.bulkKeys = []"
        x-on:click="$event.target === $el && $el.close()">
        <form wire:submit="confirmBulkMove" novalidate class="modal-panel" role="document">
            <div class="modal-head">
                <h2 id="bulk-topic-move-title" class="min-w-0 flex-1 text-lg font-semibold">Move topics</h2>
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                    <x-icon name="x" />
                </button>
            </div>
            <div class="space-y-4 px-5 pt-2">
                <p class="text-sm text-fg-muted">
                    Move {{ count($bulkKeys) }} selected {{ Str::plural('topic', count($bulkKeys)) }} to:
                </p>
                <div class="field">
                    <label for="bulk-topic-module" class="field-label">Module</label>
                    <select id="bulk-topic-module" class="input" wire:model="bulkModuleId" autofocus>
                        <option value="">Not in a module</option>
                        @foreach ($modules as $module)
                            <option value="{{ $module->id }}">{{ $module->title }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="modal-actions">
                <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" busy-label="Moving…">Move</x-button>
            </div>
        </form>
    </dialog>

    <dialog id="bulk-topic-remove-dialog" class="modal" aria-labelledby="bulk-topic-remove-title"
        wire:ignore.self
        x-data
        x-on:bulk-topic-remove-open.window="$el.open || $el.showModal()"
        x-on:bulk-topic-remove-close.window="$el.open && $el.close()"
        x-on:close="$wire.bulkKeys = []"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel" role="document">
            <div class="modal-head">
                <h2 id="bulk-topic-remove-title" class="min-w-0 flex-1 text-lg font-semibold">Remove topics?</h2>
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                    <x-icon name="x" />
                </button>
            </div>
            <div class="space-y-4 px-5 pt-2">
                <p class="text-sm text-fg-muted">
                    Remove {{ count($bulkKeys) }} selected {{ Str::plural('topic', count($bulkKeys)) }}? What you did on them stays in your study record.
                </p>
            </div>
            <div class="modal-actions">
                <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                <x-button variant="danger" wire:click="confirmBulkRemove" wire:loading.attr="aria-busy" busy-label="Removing…">Remove</x-button>
            </div>
        </div>
    </dialog>
</div>
