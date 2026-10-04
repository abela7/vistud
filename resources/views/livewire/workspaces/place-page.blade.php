{{--
    A module's or a folder's own page (App\Livewire\Workspaces\Contents, view `module` or `folder`).
    A module is the working surface (docs/specs/vistud-2-blueprint.md §3.5.3): the page template, then tabs: Topics (what the reader
    found, the topics with their status and Study, an add box), Files (the module's files with what the reader made of each,
    folders, links), Notes; Questions and Sessions are its pages of their own, behind the same tabs. Study this ▾ starts a
    session with no dialog. A folder keeps its path, its rows and its menu.
--}}
@php
    use App\Appearance\Theme;
    use App\Study\Folders;
    use Illuminate\Support\Carbon;

    $isModule = $view === 'module';
    $placeType = $view;
    $dates = $isModule && ($place->startsOn || $place->endsOn)
        ? trim(($place->startsOn ? Carbon::parse($place->startsOn)->format('j M') : '').' – '.($place->endsOn ? Carbon::parse($place->endsOn)->format('j M') : ''), ' –')
        : null;
    $understood = count(array_filter($moduleTopics, fn ($t) => in_array($t->shown(), ['understood', 'mastered'], true)));
    $context = $isModule ? implode(' · ', array_filter([$dates, $moduleTopics !== [] ? "{$understood} of ".count($moduleTopics).' understood' : null])) : null;
    $tabCounts = $isModule ? [
        'topics' => count($moduleTopics),
        'files' => $counts[$place->id]['files'] ?? 0,
        'notes' => $counts[$place->id]['notes'] ?? 0,
        'questions' => $questions['open'],
        'sessions' => $sessionsCount,
    ] : [];
    $show = ! $isModule ? 'all' : ($tab === 'files' ? 'files' : ($tab === 'notes' ? 'notes' : null));
    $statusWords = ['not_started' => 'Not started', 'covered' => 'Covered', 'understood' => 'Understood', 'confused' => 'Confusing', 'mastered' => 'Mastered'];
    $statusIcons = ['not_started' => 'circle-dot', 'covered' => 'check', 'understood' => 'circle-check', 'confused' => 'circle-alert', 'mastered' => 'shield-check'];
@endphp
<div class="space-y-6" x-data="selectable()" :class="{ 'is-selecting': isSelecting, 'is-selecting-container': isSelecting }" x-on:keydown.window="handleKeydown($event)" x-on:selection-clear.window="clearSelection()" @if ($readingNow) wire:poll.3s @endif>
    <div class="space-y-2">
        {{-- A folder's whole path, once it says more than the Back link does. --}}
        @if (! $isModule && count($trail) > 1)
            <nav aria-label="Path" class="crumbs">
                <ol role="list">
                    @foreach ($trail as [$label, $url])
                        <li><a href="{{ $url }}">{{ $label }}</a></li>
                    @endforeach
                </ol>
            </nav>
        @endif
        <x-page :title="$placeName" :back-href="end($trail)[1]" :back-to="end($trail)[0]" :eyebrow="$isModule ? $workspace->name : null" :context="$context !== '' ? $context : null">
            <x-slot:menu>
                @if ($isModule)
                    <button type="button" class="menu-item" wire:click="editModule('{{ $place->id }}')"><x-icon name="pencil" class="size-4" />Edit module</button>
                    <button type="button" class="menu-item" wire:click="editInstructions('{{ $place->id }}')"><x-icon name="message-square-text" class="size-4" />Tell the tutor about this module</button>
                    <button type="button" class="menu-item" wire:click="confirmDelete('module', '{{ $place->id }}')"><x-icon name="trash-2" class="size-4" />Delete module</button>
                @else
                    <button type="button" class="menu-item" wire:click="renameFolder('{{ $place->id }}')"><x-icon name="pencil" class="size-4" />Rename</button>
                    <button type="button" class="menu-item" wire:click="moveFolder('{{ $place->id }}')"><x-icon name="folder-input" class="size-4" />Move to…</button>
                    <button type="button" class="menu-item" wire:click="confirmDelete('folder', '{{ $place->id }}')"><x-icon name="trash-2" class="size-4" />Delete</button>
                @endif
            </x-slot:menu>
            <x-slot:action>
                @if ($isModule)
                    <div class="relative max-w-full">
                        <button type="button" class="btn btn-primary btn-lg max-w-full whitespace-normal" wire:ignore.self data-menu-button aria-controls="study-menu" aria-expanded="false"><x-icon name="play" class="size-4" />Study this<x-icon name="chevron-down" class="size-4" /></button>
                        <div id="study-menu" class="row-menu" wire:ignore.self data-menu-panel popover="manual" hidden>
                            <button type="button" class="menu-item" wire:click="studyModule"><x-icon name="layers" class="size-4" />Whole module</button>
                            <button type="button" class="menu-item" wire:click="pickTopic" @disabled($moduleTopics === [])><x-icon name="list-checks" class="size-4" />Pick a topic</button>
                            <button type="button" class="menu-item" wire:click="studyAsk('quiz')"><x-icon name="circle-help" class="size-4" />Quiz me</button>
                            <button type="button" class="menu-item" wire:click="studyAsk('test')"><x-icon name="clipboard-check" class="size-4" />Test me</button>
                        </div>
                    </div>
                @elseif ($place->moduleId !== null)
                    <x-button variant="primary" size="lg" icon="play" wire:click="studyModule">Study this</x-button>
                @endif
            </x-slot:action>
        </x-page>
    </div>

    @if ($isModule)
        <x-module.tabs :workspace-id="$workspaceId" :module-id="$place->id" :current="$tab" :counts="$tabCounts" />
    @endif

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" :action-label="$noticeActionLabel" :action-event="$noticeActionEvent" :action-payload="$noticeActionPayload" />
    </div>

    <x-selection-bar>
        <x-button variant="secondary" size="sm" icon="folder-input" ::disabled="count === 0" x-on:click="$wire.openBulkMove(selectedKeys())">Move…</x-button>
        <x-button variant="secondary" size="sm" icon="trash-2" ::disabled="count === 0 || !selectedKeys().some(k => k.startsWith('note:') || k.startsWith('file:'))" title="Move selected notes and files to the trash" x-on:click="$wire.bulk('trash', selectedKeys().filter(k => k.startsWith('note:') || k.startsWith('file:')))">Move to trash</x-button>
        <x-button variant="danger" size="sm" icon="trash-2" ::disabled="count === 0 || !selectedKeys().some(k => k.startsWith('folder:') || k.startsWith('link:'))" title="Delete selected folders and links" x-on:click="$wire.openBulkDelete(selectedKeys().filter(k => k.startsWith('folder:') || k.startsWith('link:')))">Delete</x-button>
    </x-selection-bar>

    @if ($isModule && $tab === 'topics')
        {{-- What the reader found in the module's files, until the student adds or dismisses it. --}}
        @if ($suggestions !== [])
            <section class="space-y-2" aria-label="New topics found">
                <div class="suggestion-bar">
                    <x-icon name="sparkles" class="size-5 shrink-0" />
                    <p><span class="font-semibold">{{ count($suggestions) }} new {{ count($suggestions) === 1 ? 'topic' : 'topics' }} found</span>{{ $suggestions[0]->sourceName !== null ? ' in '.$suggestions[0]->sourceName : '' }}: {{ implode(', ', array_map(fn ($s) => $s->name, array_slice($suggestions, 0, 4))) }}{{ count($suggestions) > 4 ? '…' : '' }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-button size="sm" variant="primary" wire:click="addSuggested" wire:loading.attr="aria-busy" wire:target="addSuggested" busy-label="Adding…">Add all</x-button>
                        <x-button size="sm" wire:click="pickSuggested" aria-expanded="{{ $picking ? 'true' : 'false' }}">Pick</x-button>
                        <x-button size="sm" variant="ghost" wire:click="dismissSuggested">Not these</x-button>
                    </div>
                </div>
                @if ($picking)
                    <ul class="item-list" role="list">
                        @foreach ($suggestions as $suggestion)
                            <li class="item-row" wire:key="suggestion-{{ $suggestion->id }}">
                                <span class="min-w-0 flex-1 font-medium break-words">{{ $suggestion->name }}</span>
                                <x-button size="sm" icon="plus" wire:click="addOneSuggested('{{ $suggestion->id }}')" aria-label="Add {{ $suggestion->name }}">Add</x-button>
                                <button type="button" class="topbar-button" wire:click="dismissOneSuggested('{{ $suggestion->id }}')" aria-label="Not {{ $suggestion->name }}"><x-icon name="x" class="size-4" /></button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        {{-- The module's topics: the parts it is made of, each with how it stands. --}}
        <section class="module-topics" aria-labelledby="module-topics-heading">
            <h2 id="module-topics-heading" class="sr-only">Topics</h2>
            @if ($moduleTopics === [])
                <div class="empty-place">
                    <span class="item-icon" aria-hidden="true"><x-icon name="list-checks" class="size-5" /></span>
                    <p class="font-medium">No topics yet</p>
                </div>
            @else
                <ul class="module-topic-list" role="list">
                    @foreach ($moduleTopics as $t)
                        @php
                            $shown = $t->shown();
                            $tc = $topicCards[$t->id] ?? null;
                        @endphp
                        <li class="module-topic" wire:key="module-topic-{{ $t->id }}">
                            <div class="min-w-0 flex-1">
                                <button type="button" class="font-medium break-words text-left" x-on:click="Livewire.dispatch('topic-sheet-open', { topicId: '{{ $t->id }}' })">{{ $t->name }}</button>
                                <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-fg-muted">
                                    <span @class(['status-chip', "status-{$shown}"])><x-icon :name="$statusIcons[$shown]" class="size-3.5" />{{ $statusWords[$shown] }}</span>
                                    @if ($tc)
                                        <a href="{{ route('workspaces.show', [$workspaceId, 'flashcards', 'module' => $place->id, 'topic' => $t->id]) }}" class="item-link">{{ Str::plural('card', $tc['total'], prependCount: true) }}{{ $tc['due'] > 0 ? ', '.$tc['due'].' due' : '' }}</a>
                                    @endif
                                </p>
                            </div>
                            <x-button size="sm" icon="play" wire:click="studyTopic('{{ $t->id }}')" aria-label="Study {{ $t->name }}">Study</x-button>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form wire:submit="addTopic" class="module-topic-add" novalidate>
                <label for="module-topic-name" class="sr-only">Add a topic to {{ $placeName }}</label>
                <input id="module-topic-name" type="text" class="input" wire:model="topicName" maxlength="{{ \App\Study\Topics::MAX_NAME }}" placeholder="Add a topic, like “CPU scheduling”" @error('topicName') aria-invalid="true" aria-describedby="module-topic-error" @enderror>
                <x-button type="submit" icon="plus" wire:loading.attr="aria-busy" wire:target="addTopic">Add</x-button>
            </form>
            @error('topicName') <p id="module-topic-error" class="field-error">{{ $message }}</p> @enderror
        </section>
    @else
        {{-- Files and Notes tabs, and a folder: what is kept here, with the actions that add to it. --}}
        <div class="flex flex-wrap items-center gap-2">
            @if ($show === 'notes')
                <x-button icon="file-plus" wire:click="newNote('{{ $placeType }}', '{{ $place->id }}')">New note</x-button>
            @else
                <x-button icon="upload" wire:click="uploadFiles('{{ $placeType }}', '{{ $place->id }}')">Upload files</x-button>
                <x-button icon="folder-plus" wire:click="newFolder('{{ $placeType }}', '{{ $place->id }}')" :disabled="$isModule ? false : $place->depth >= Folders::MAX_DEPTH">New folder</x-button>
                <x-button icon="link" wire:click="newLink('{{ $placeType }}', '{{ $place->id }}')">Add a link</x-button>
                @if (! $isModule)
                    <x-button icon="file-plus" wire:click="newNote('{{ $placeType }}', '{{ $place->id }}')">New note</x-button>
                @endif
            @endif
            <button type="button" class="btn btn-secondary" x-on:click="toggleMode()" :aria-pressed="isSelecting ? 'true' : 'false'">
                <x-icon name="list-checks" class="size-4" />
                <span x-text="isSelecting ? 'Done' : 'Select'">Select</span>
            </button>
        </div>
        @include('livewire.workspaces.partials.place', ['key' => $key, 'placeId' => $place->id, 'show' => $show ?? 'all'])
    @endif

    @include('livewire.workspaces.partials.dialog')

    @if ($isModule)
        {{-- Pick a topic: the module's topics on a sheet; choosing one starts a session on it. --}}
        <dialog id="topic-picker" class="modal" aria-labelledby="topic-picker-title"
            wire:ignore.self
            x-data
            x-on:topic-picker-open.window="$el.open || $el.showModal()"
            x-on:click="$event.target === $el && $el.close()">
            <div class="modal-panel">
                <div class="modal-head">
                    <h2 id="topic-picker-title" class="min-w-0 flex-1 text-lg font-semibold">Pick a topic</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()"><x-icon name="x" /></button>
                </div>
                <ul class="item-list mx-5 mb-5" role="list">
                    @foreach ($moduleTopics as $t)
                        <li class="item-row" wire:key="pick-{{ $t->id }}">
                            <span class="min-w-0 flex-1 font-medium break-words">{{ $t->name }}</span>
                            <x-button size="sm" icon="play" wire:click="studyTopic('{{ $t->id }}')" x-on:click="$el.closest('dialog').close()" aria-label="Study {{ $t->name }}">Study</x-button>
                        </li>
                    @endforeach
                </ul>
            </div>
        </dialog>
        <livewire:workspaces.topic-sheet :workspace-id="$workspaceId" :key="'topic-sheet-'.$place->id" />
    @endif
</div>
