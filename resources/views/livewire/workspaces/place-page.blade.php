{{--
    A module's or a folder's own page (App\Livewire\Workspaces\Contents, view `module` or `folder`).
    A module is the working surface (docs/specs/vistud-2-blueprint.md §3.5.3): the page template, then tabs: Topics (what the reader
    found, the topics with their status and Study, under the module's folders, each with its own Study; an add box), Files (the
    module's files with what the reader made of each, folders, links), Notes; Questions and Sessions are its pages of their own,
    behind the same tabs. Study this ▾ starts a session with no dialog. A folder in a module is a place to study (Phase 9): the
    module above its title, Study this ▾ for the whole folder, and tabs of its own: Topics · Files · Notes · Questions · Cards,
    each only what is in it. A folder outside every module keeps its path, its rows and its menu.
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
    $understood = count(array_filter($placeTopics, fn ($t) => in_array($t->shown(), ['understood', 'mastered'], true)));
    $context = $studyPlace ? implode(' · ', array_filter([$dates, $placeTopics !== [] ? "{$understood} of ".count($placeTopics).' understood' : null])) : null;
    // A folder's own counts take in the folders inside it.
    $within = fn (array $byPlace) => collect($inside ?? [])->sum(fn ($id) => count($byPlace["folder:{$id}"] ?? []));
    $tabCounts = match (true) {
        $isModule => [
            'topics' => count($placeTopics),
            'files' => $counts[$place->id]['files'] ?? 0,
            'notes' => $counts[$place->id]['notes'] ?? 0,
            'questions' => $questions['open'],
            'sessions' => $sessionsCount,
        ],
        $studyPlace => [
            'topics' => count($placeTopics),
            'files' => $within($filesIn),
            'notes' => $within($notesIn),
            'questions' => $questions['open'],
            'cards' => $cards['total'],
        ],
        default => [],
    };
    $show = ! $studyPlace ? 'all' : ($tab === 'files' ? 'files' : ($tab === 'notes' ? 'notes' : null));
    $statusWords = ['not_started' => 'Not started', 'covered' => 'Covered', 'understood' => 'Understood', 'confused' => 'Confusing', 'mastered' => 'Mastered'];
    $statusIcons = ['not_started' => 'circle-dot', 'covered' => 'check', 'understood' => 'circle-check', 'confused' => 'circle-alert', 'mastered' => 'shield-check'];
    $moduleId = $placeModule?->id;
    // A module's Topics tab is grouped by folder once the module has one.
    $grouped = $isModule && collect($topicGroups)->contains(fn ($group) => $group['folder'] !== null);
    $questionLook = ['pending' => ['circle-dot', 'blue'], 'stuck' => ['circle-alert', 'red'], 'answered' => ['circle-check', 'green']];
@endphp
<div class="space-y-6" x-data="selectable()" :class="{ 'is-selecting': isSelecting, 'is-selecting-container': isSelecting }" x-on:keydown.window="handleKeydown($event)" x-on:selection-clear.window="clearSelection()" @if ($readingNow) wire:poll.3s @endif>
    <div class="space-y-2">
        {{-- A folder's whole path, once it says more than the Back link does (and, for a folder in a module, more than the
             module's name above its title). --}}
        @if (! $isModule && count($trail) > ($studyPlace ? 2 : 1))
            <nav aria-label="Path" class="crumbs">
                <ol role="list">
                    @foreach ($trail as [$label, $url])
                        <li><a href="{{ $url }}">{{ $label }}</a></li>
                    @endforeach
                </ol>
            </nav>
        @endif
        <x-page :title="$placeName" :back-href="end($trail)[1]" :back-to="end($trail)[0]" :eyebrow="$isModule ? $workspace->name : $placeModule?->title" :context="$context !== '' ? $context : null">
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
                @if ($studyPlace)
                    <div class="relative max-w-full">
                        <button type="button" class="btn btn-primary btn-lg max-w-full whitespace-normal" wire:ignore.self data-menu-button aria-controls="study-menu" aria-expanded="false"><x-icon name="play" class="size-4" />Study this<x-icon name="chevron-down" class="size-4" /></button>
                        <div id="study-menu" class="row-menu" wire:ignore.self data-menu-panel popover="manual" hidden>
                            <button type="button" class="menu-item" wire:click="studyModule"><x-icon name="layers" class="size-4" />{{ $isModule ? 'Whole module' : 'Whole folder' }}</button>
                            <button type="button" class="menu-item" wire:click="pickTopic" @disabled($placeTopics === [])><x-icon name="list-checks" class="size-4" />Pick a topic</button>
                            <button type="button" class="menu-item" wire:click="studyAsk('quiz')"><x-icon name="circle-help" class="size-4" />Quiz me</button>
                            <button type="button" class="menu-item" wire:click="studyAsk('test')"><x-icon name="clipboard-check" class="size-4" />Test me</button>
                        </div>
                    </div>
                @endif
            </x-slot:action>
        </x-page>
    </div>

    @if ($isModule)
        <x-module.tabs :workspace-id="$workspaceId" :module-id="$place->id" :current="$tab" :counts="$tabCounts" />
    @elseif ($studyPlace)
        <x-folder.tabs :workspace-id="$workspaceId" :folder-id="$place->id" :current="$tab" :counts="$tabCounts" />
    @endif

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" :action-label="$noticeActionLabel" :action-event="$noticeActionEvent" :action-payload="$noticeActionPayload" />
    </div>

    <x-selection-bar>
        <x-button variant="secondary" size="sm" icon="folder-input" ::disabled="count === 0" x-on:click="$wire.openBulkMove(selectedKeys())">Move…</x-button>
        <x-button variant="secondary" size="sm" icon="trash-2" ::disabled="count === 0 || !selectedKeys().some(k => k.startsWith('note:') || k.startsWith('file:'))" title="Move selected notes and files to the trash" x-on:click="$wire.bulk('trash', selectedKeys().filter(k => k.startsWith('note:') || k.startsWith('file:')))">Move to trash</x-button>
        <x-button variant="danger" size="sm" icon="trash-2" ::disabled="count === 0 || !selectedKeys().some(k => k.startsWith('folder:') || k.startsWith('link:'))" title="Delete selected folders and links" x-on:click="$wire.openBulkDelete(selectedKeys().filter(k => k.startsWith('folder:') || k.startsWith('link:')))">Delete</x-button>
    </x-selection-bar>

    @if ($studyPlace && $tab === 'topics')
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

        {{-- The place's topics: the parts it is made of, each with how it stands; a module's under its folders. --}}
        <section class="module-topics" aria-labelledby="module-topics-heading">
            <h2 id="module-topics-heading" class="sr-only">Topics</h2>
            @if ($grouped)
                @foreach ($topicGroups as $group)
                    @php
                        $folder = $group['folder'];
                        $done = count(array_filter($group['topics'], fn ($t) => in_array($t->shown(), ['understood', 'mastered'], true)));
                    @endphp
                    <section class="topic-group" wire:key="topic-group-{{ $folder?->id ?? 'module' }}" aria-label="{{ $folder?->name ?? 'In the module' }}">
                        <div class="topic-group-head">
                            @if ($folder)
                                <span class="item-icon ws-colour-amber" aria-hidden="true"><x-icon name="folder" class="size-5" /></span>
                                <div class="min-w-0 flex-1">
                                    <h3 class="topic-group-title"><a href="{{ route('workspaces.folders.show', [$workspaceId, $folder->id]) }}">{{ $folder->name }}</a></h3>
                                    <p class="text-sm text-fg-muted">{{ $group['topics'] === [] ? 'No topics yet' : "{$done} of ".count($group['topics']).' understood' }}</p>
                                </div>
                                <x-button size="sm" variant="primary" icon="play" wire:click="studyFolder('{{ $folder->id }}')" aria-label="Study {{ $folder->name }}">Study</x-button>
                            @else
                                <h3 class="topic-group-title">In the module</h3>
                            @endif
                        </div>
                        @if ($group['topics'] !== [])
                            <ul class="module-topic-list" role="list">
                                @foreach ($group['topics'] as $t)
                                    @include('livewire.workspaces.partials.topic-row', ['t' => $t])
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endforeach
            @elseif ($placeTopics === [])
                <div class="empty-place">
                    <span class="item-icon" aria-hidden="true"><x-icon name="list-checks" class="size-5" /></span>
                    <p class="font-medium">No topics yet</p>
                    @if (! $isModule)
                        <p class="text-sm text-fg-muted">Put the lecture and its lab in Files: the AI reads them and finds the topics. Or add one below.</p>
                        <a href="{{ route('workspaces.folders.show', [$workspaceId, $place->id, 'tab' => 'files']) }}" class="btn btn-secondary"><x-icon name="upload" class="size-4" />Go to Files</a>
                    @endif
                </div>
            @else
                <ul class="module-topic-list" role="list">
                    @foreach ($placeTopics as $t)
                        @include('livewire.workspaces.partials.topic-row', ['t' => $t])
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
    @elseif ($studyPlace && $tab === 'questions')
        {{-- A folder's questions: what the student doesn't get yet about what is in it. --}}
        <section class="space-y-3" aria-labelledby="folder-questions-heading">
            <h2 id="folder-questions-heading" class="sr-only">Questions</h2>
            <form wire:submit="askQuestion" class="module-topic-add" novalidate>
                <label for="folder-question-text" class="sr-only">Ask a question about {{ $placeName }}</label>
                <input id="folder-question-text" type="text" class="input" wire:model="questionText" maxlength="1000" placeholder="Something you don't get yet…" @error('questionText') aria-invalid="true" aria-describedby="folder-question-error" @enderror>
                <x-button type="submit" icon="plus" wire:loading.attr="aria-busy" wire:target="askQuestion">Add</x-button>
            </form>
            @error('questionText') <p id="folder-question-error" class="field-error">{{ $message }}</p> @enderror
            @if ($asked === [])
                <div class="empty-place">
                    <span class="item-icon" aria-hidden="true"><x-icon name="circle-help" class="size-5" /></span>
                    <p class="font-medium">No questions yet</p>
                    <p class="text-sm text-fg-muted">Questions you or the AI add while studying this folder appear here.</p>
                </div>
            @else
                <ul class="item-list" role="list" aria-label="Questions">
                    @foreach ($asked as $q)
                        <li class="item-row" wire:key="folder-question-{{ $q->id }}">
                            @php [$qIcon, $qColour] = $questionLook[$q->status] ?? $questionLook['pending']; @endphp
                            <span class="question-status ws-colour-{{ $qColour }}"><x-icon :name="$qIcon" class="size-4" />{{ $q->statusLabel() }}</span>
                            <span class="min-w-0 flex-1">
                                <a href="{{ route('workspaces.questions.show', [$workspaceId, $q->id]) }}" class="tile-link">{{ Str::limit($q->text, 160) }}</a>
                                @if ($q->topicId !== null && isset($topicNames[$q->topicId]))
                                    <span class="item-meta">{{ $topicNames[$q->topicId] }}</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @elseif ($studyPlace && $tab === 'cards')
        {{-- A folder's cards: how many, how many are due, a round of review, and what they ask. --}}
        <section class="space-y-3" aria-labelledby="folder-cards-heading">
            <h2 id="folder-cards-heading" class="sr-only">Cards</h2>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <p class="text-sm text-fg-muted">{{ $cards['total'] === 0 ? 'No cards yet' : Str::plural('card', $cards['total'], prependCount: true).($cards['due'] > 0 ? ', '.$cards['due'].' due today' : ', none due today') }}</p>
                @if ($cards['due'] > 0)
                    <a href="{{ route('workspaces.flashcards.review', [$workspaceId, 'folder' => $place->id]) }}" class="btn btn-primary"><x-icon name="play" class="size-4" />Review {{ $cards['due'] }}</a>
                @elseif ($cards['total'] > 0)
                    <a href="{{ route('workspaces.flashcards.review', [$workspaceId, 'folder' => $place->id, 'early' => 1]) }}" class="btn btn-secondary"><x-icon name="play" class="size-4" />Practise early</a>
                @endif
                @if ($cards['total'] > 0)
                    <a href="{{ route('workspaces.show', [$workspaceId, 'flashcards', 'folder' => $place->id]) }}" class="btn btn-secondary"><x-icon name="layers" class="size-4" />Open in Cards</a>
                @endif
            </div>
            @if ($cardList === [])
                <div class="empty-place">
                    <span class="item-icon" aria-hidden="true"><x-icon name="layers" class="size-5" /></span>
                    <p class="font-medium">No cards yet</p>
                    <p class="text-sm text-fg-muted">Cards made while studying this folder appear here, ready to review.</p>
                </div>
            @else
                <ul class="item-list" role="list" aria-label="Cards">
                    @foreach ($cardList as $card)
                        <li class="item-row" wire:key="folder-card-{{ $card->id }}">
                            <span class="item-icon" aria-hidden="true"><x-icon name="layers" class="size-5" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium break-words">{{ Str::limit($card->front, 160) }}</span>
                                <span class="item-meta">{{ $card->topicId !== null && isset($topicNames[$card->topicId]) ? $topicNames[$card->topicId].' · ' : '' }}{{ $card->isNew() ? 'New' : 'Next on '.Carbon::parse($card->dueOn)->format('j M') }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
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
                @if (! $studyPlace)
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

    @if ($studyPlace)
        {{-- Pick a topic: the place's topics on a sheet; choosing one starts a session on it. --}}
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
                    @foreach ($placeTopics as $t)
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
