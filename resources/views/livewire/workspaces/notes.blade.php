{{--
    A course's All notes & files (App\Livewire\Workspaces\Contents, view `notes`; reached from Modules): a search across
    the whole course, what sits outside every module, its folders to open, and the trash.
--}}
@php
    use App\Study\Notes;
    use Illuminate\Support\Carbon;

    $top = "workspace:{$workspaceId}";
@endphp
<div class="space-y-5" x-data="selectable()" :class="{ 'is-selecting': isSelecting, 'is-selecting-container': isSelecting }" x-on:keydown.window="handleKeydown($event)" x-on:selection-clear.window="clearSelection()">
    {{-- One row of heading and actions, like Modules. While selecting, the selection bar's Done ends it. --}}
    <x-workspace.section-header :workspace="$workspace" title="Notes & files">
        <button type="button" class="btn btn-secondary" x-show="!isSelecting" x-on:click="toggleMode(); $nextTick(() => $root.querySelector('.selection-all-box input')?.focus())">
            <x-icon name="list-checks" class="size-4" />
            <span class="max-md:sr-only">Select</span>
        </button>
        @include('livewire.workspaces.partials.new-menu', ['placeType' => 'workspace', 'placeId' => $workspaceId, 'primary' => true])
    </x-workspace.section-header>

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" :action-label="$noticeActionLabel" :action-event="$noticeActionEvent" :action-payload="$noticeActionPayload" />
    </div>

    <x-selection-bar>
        @if (! $showTrash)
            <x-button variant="secondary" size="sm" icon="folder-input" ::disabled="count === 0" x-on:click="$wire.openBulkMove(selectedKeys())">Move…</x-button>
            <x-button variant="secondary" size="sm" icon="trash-2" ::disabled="count === 0 || !selectedKeys().some(k => k.startsWith('note:') || k.startsWith('file:'))" title="Move selected notes and files to the trash" x-on:click="$wire.bulk('trash', selectedKeys().filter(k => k.startsWith('note:') || k.startsWith('file:')))">Move to trash</x-button>
            <x-button variant="danger" size="sm" icon="trash-2" ::disabled="count === 0 || !selectedKeys().some(k => k.startsWith('folder:') || k.startsWith('link:'))" title="Delete selected folders and links" x-on:click="$wire.openBulkDelete(selectedKeys().filter(k => k.startsWith('folder:') || k.startsWith('link:')))">Delete</x-button>
        @else
            <x-button variant="secondary" size="sm" icon="rotate-ccw" ::disabled="count === 0" x-on:click="$wire.bulk('restore', selectedKeys())">Restore</x-button>
            <x-button variant="danger" size="sm" icon="trash-2" ::disabled="count === 0" x-on:click="$wire.openBulkDestroy(selectedKeys())">Delete forever</x-button>
        @endif
    </x-selection-bar>

    {{-- Looking for something anywhere in the course (the Modules page links here with the words). --}}
    <div class="search-field">
        <x-icon name="search" class="size-4" />
        <label for="notes-search" class="sr-only">Search notes and files</label>
        <input id="notes-search" type="search" class="input" placeholder="Search notes and files" wire:model.live.debounce.300ms="search" autocomplete="off">
    </div>

    @if ($results !== null)
        @if ($results === [])
            <div class="empty-place">
                <span class="item-icon" aria-hidden="true"><x-icon name="search" class="size-5" /></span>
                <p class="font-medium">Nothing matches</p>
            </div>
        @else
            <ul class="item-list" role="list" aria-label="Search results">
                @foreach ($results as $result)
                    <li class="item-row">
                        <span class="item-icon" aria-hidden="true"><x-icon :name="$result['icon']" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <a href="{{ $result['url'] }}" class="tile-link">{{ $result['name'] }}</a>
                            <span class="item-meta">{{ implode(' · ', array_filter([$result['kind'], $result['place']])) }}</span>
                        </span>
                        <x-icon name="chevron-right" class="size-5 shrink-0 text-fg-subtle" />
                    </li>
                @endforeach
            </ul>
        @endif
    @else
    @include('livewire.workspaces.partials.place', ['key' => $top, 'placeType' => 'workspace', 'placeId' => $workspaceId])

    <section aria-labelledby="trash-heading" class="space-y-2">
        <h2 id="trash-heading" class="sr-only">Trash</h2>
        @php $trashCount = count($trash) + count($trashedFiles); @endphp
        <x-button variant="ghost" icon="trash-2" wire:click="toggleTrash" aria-expanded="{{ $showTrash ? 'true' : 'false' }}" aria-controls="trash-list">
            {{ $showTrash ? 'Hide the trash' : 'Trash' }} ({{ $trashCount }})
        </x-button>
        @if ($showTrash)
            <div id="trash-list" class="module-card">
                @if ($trashCount === 0)
                    <p class="px-4 py-3 text-sm text-fg-muted">The trash is empty.</p>
                @else
                    <p class="border-b border-divider px-4 py-3 text-sm text-fg-muted">Deleted for good after {{ Notes::TRASH_DAYS }} days.</p>
                    <ul class="divide-y divide-divider" role="list">
                        @foreach ($trash as $note)
                            <li wire:key="trash-{{ $note->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3"
                                data-select-key="note:{{ $note->id }}"
                                :class="{ 'is-selected': isSelected('note:{{ $note->id }}') }"
                                x-on:click="handleRowClick($event, 'note:{{ $note->id }}')">
                                <x-selection-check key="note:{{ $note->id }}" label="Select {{ $note->displayTitle() }}" />
                                <x-icon name="file-text" class="size-5 shrink-0 text-fg-muted" />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium break-words">{{ $note->displayTitle() }}</span>
                                    <span class="block text-sm text-fg-muted">Note · trashed {{ Carbon::parse($note->trashedAt)->diffForHumans() }}</span>
                                </span>
                                <span class="flex flex-wrap gap-2">
                                    <x-button icon="rotate-ccw" wire:click="restoreNote('{{ $note->id }}')" aria-label="Restore {{ $note->displayTitle() }}">Restore</x-button>
                                    <x-button variant="ghost" wire:click="confirmDelete('note', '{{ $note->id }}')" aria-label="Delete for good: {{ $note->displayTitle() }}">Delete for good</x-button>
                                </span>
                            </li>
                        @endforeach
                        @foreach ($trashedFiles as $file)
                            <li wire:key="trash-{{ $file->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3"
                                data-select-key="file:{{ $file->id }}"
                                :class="{ 'is-selected': isSelected('file:{{ $file->id }}') }"
                                x-on:click="handleRowClick($event, 'file:{{ $file->id }}')">
                                <x-selection-check key="file:{{ $file->id }}" label="Select {{ $file->fileName() }}" />
                                <x-icon :name="$file->icon()" class="size-5 shrink-0 text-fg-muted" />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium break-words">{{ $file->fileName() }}</span>
                                    <span class="block text-sm text-fg-muted">{{ $file->typeLabel() }} · {{ $file->humanSize() }} · trashed {{ Carbon::parse($file->trashedAt)->diffForHumans() }}</span>
                                </span>
                                <span class="flex flex-wrap gap-2">
                                    <x-button icon="rotate-ccw" wire:click="restoreFile('{{ $file->id }}')" aria-label="Restore {{ $file->fileName() }}">Restore</x-button>
                                    <x-button variant="ghost" wire:click="confirmDelete('file', '{{ $file->id }}')" aria-label="Delete for good: {{ $file->fileName() }}">Delete for good</x-button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </section>
    @endif

    @include('livewire.workspaces.partials.dialog')
</div>
