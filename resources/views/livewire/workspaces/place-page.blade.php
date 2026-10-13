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
    use App\Study\ModuleParts;
    use Illuminate\Support\Carbon;

    $isModule = $view === 'module';
    $placeType = $view;
    $dates = $isModule && ($place->startsOn || $place->endsOn)
        ? trim(($place->startsOn ? Carbon::parse($place->startsOn)->format('j M') : '').' – '.($place->endsOn ? Carbon::parse($place->endsOn)->format('j M') : ''), ' –')
        : null;
    $understood = count(array_filter($placeTopics, fn ($t) => in_array($t->shown(), ['understood', 'mastered'], true)));
    // A module's line of what it is about comes first: a lab week reads as one.
    $context = $studyPlace ? implode(' · ', array_filter([$isModule ? $place->about : null, $dates,$placeTopics !== [] ? "{$understood} of ".count($placeTopics).' understood' : null])) : null;
    $tabCounts = match (true) {
        $isModule => [
            'folders' => count($moduleFolders ?? []),
            'files' => count($moduleFiles ?? []),
            'notes' => count($moduleNotes ?? []),
            'topics' => count($placeTopics),
            'questions' => $questions['open'],
            'sessions' => $sessionsCount,
        ],
        $studyPlace => [
            'dashboard' => 0,
            'topics' => count($placeTopics),
            'files' => count($folderFiles ?? $filesIn[$key] ?? []),
            'notes' => count($folderNotes ?? $notesIn[$key] ?? []),
            'questions' => $questions['open'],
            'cards' => $cards['total'],
        ],
        default => [],
    };
    $show = ! $studyPlace ? 'all' : ($tab === 'files' ? 'files' : ($tab === 'notes' ? 'notes' : null));
    $statusWords = \App\Study\TopicDetails::STATUS_WORDS;
    $statusIcons = \App\Study\TopicDetails::STATUS_ICONS;
    $fileColours = ['pdf' => 'red', 'document' => 'blue', 'slides' => 'orange', 'spreadsheet' => 'green', 'text' => 'pink', 'image' => 'purple'];
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
                    <a href="{{ route('workspaces.show', [$workspaceId, 'flashcards', 'module' => $place->id]) }}" class="menu-item"><x-icon name="gallery-vertical-end" class="size-4" />Cards</a>
                    <button type="button" class="menu-item" wire:click="editInstructions('{{ $place->id }}')"><x-icon name="message-square-text" class="size-4" />Tell the tutor about this module</button>
                    <button type="button" class="menu-item" wire:click="confirmDelete('module', '{{ $place->id }}')"><x-icon name="trash-2" class="size-4" />Delete module</button>
                @else
                    <button type="button" class="menu-item" wire:click="renameFolder('{{ $place->id }}')"><x-icon name="pencil" class="size-4" />Rename</button>
                    <button type="button" class="menu-item" wire:click="moveFolder('{{ $place->id }}')"><x-icon name="folder-input" class="size-4" />Move to…</button>
                    <button type="button" class="menu-item" wire:click="confirmDelete('folder', '{{ $place->id }}')"><x-icon name="trash-2" class="size-4" />Delete</button>
                @endif
            </x-slot:menu>
            <x-slot:action>
                @if ($studyPlace && (! $isModule || ! $grouped))
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

    @if ($isModule && $tab === 'folders')
        {{-- A module's Folders tab: the sessions dashboard (lectures, labs, study units) --}}
        <div class="flex flex-wrap items-center gap-2">
            <x-button icon="folder-plus" wire:click="newFolder('module', '{{ $place->id }}')">New folder</x-button>
            <button type="button" class="btn btn-secondary" x-on:click="toggleMode()" :aria-pressed="isSelecting ? 'true' : 'false'">
                <x-icon name="list-checks" class="size-4" />
                <span x-text="isSelecting ? 'Done' : 'Select'">Select</span>
            </button>
        </div>
        @if ($moduleFolders !== [])
            @php
                $topicsByFolder = collect($topicGroups ?? [])
                    ->filter(fn ($g) => ($g['folder'] ?? null) !== null)
                    ->keyBy(fn ($g) => $g['folder']->id);
            @endphp
            <ul class="folder-grid" role="list" aria-label="Folders">
                @foreach ($moduleFolders as $i => $folder)
                    @php
                        $inside = $itemCounts[$folder->id] ?? 0;
                        $folderFiles = count($filesIn["folder:{$folder->id}"] ?? []);
                        $folderNotes = count($notesIn["folder:{$folder->id}"] ?? []);
                        $folderLinks = count($linksIn["folder:{$folder->id}"] ?? []);
                        $folderSubs = count($children["folder:{$folder->id}"] ?? []);
                        $materialParts = [];
                        if ($folderFiles > 0) $materialParts[] = $folderFiles . ' ' . \Illuminate\Support\Str::plural('file', $folderFiles);
                        if ($folderNotes > 0) $materialParts[] = $folderNotes . ' ' . \Illuminate\Support\Str::plural('note', $folderNotes);
                        if ($folderLinks > 0) $materialParts[] = $folderLinks . ' ' . \Illuminate\Support\Str::plural('link', $folderLinks);
                        if ($folderSubs > 0) $materialParts[] = $folderSubs . ' ' . \Illuminate\Support\Str::plural('subfolder', $folderSubs);

                        $folderGroup = $topicsByFolder[$folder->id] ?? null;
                        $topicCount = $folderGroup ? count($folderGroup['topics']) : 0;
                        if ($topicCount > 0) {
                            $materialParts[] = $topicCount . ' ' . \Illuminate\Support\Str::plural('topic', $topicCount);
                        }
                        $materialsDesc = $materialParts !== [] ? implode(' · ', $materialParts) : ($inside === 0 ? 'Empty session' : ($inside === 1 ? '1 item' : $inside.' items'));
                    @endphp
                    <li class="folder-tile group" wire:key="folder-{{ $folder->id }}"
                        data-select-key="folder:{{ $folder->id }}"
                        :class="{ 'is-selected': isSelected('folder:{{ $folder->id }}') }"
                        x-on:click="handleRowClick($event, 'folder:{{ $folder->id }}')">
                        <div class="folder-card-head">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <x-selection-check key="folder:{{ $folder->id }}" label="Select {{ $folder->name }}" />
                                <span class="folder-card-icon" aria-hidden="true">
                                    <x-icon :name="ModuleParts::icon($folder->kind)" class="size-4.5" />
                                </span>
                                @if (ModuleParts::known($folder->kind))
                                    <span class="badge text-xs font-medium shrink-0">{{ ModuleParts::label($folder->kind) }}</span>
                                @endif
                                <span class="badge badge-neutral text-xs font-medium shrink-0">
                                    {{ $inside === 0 ? 'Empty' : ($inside === 1 ? '1 item' : $inside.' items') }}
                                </span>
                            </div>
                            <div class="relative z-10 flex items-center gap-1 shrink-0 -mr-1">
                                <x-ai-menu kind="folder" :id="$folder->id" :label="$folder->name" />
                                @include('livewire.workspaces.partials.row-menu', ['id' => $folder->id, 'label' => $folder->name, 'items' => [
                                    ['Rename', 'pencil', "renameFolder('{$folder->id}')", false],
                                    ['Move to…', 'folder-input', "moveFolder('{$folder->id}')", false],
                                    ['Move up', 'arrow-up', "moveFolderBy('{$folder->id}', -1)", $i === 0],
                                    ['Move down', 'arrow-down', "moveFolderBy('{$folder->id}', 1)", $i === count($moduleFolders) - 1],
                                    ['Delete', 'trash-2', "confirmDelete('folder', '{$folder->id}')", false],
                                ]])
                            </div>
                        </div>
                        <div class="folder-card-body">
                            <h3 class="folder-card-title">
                                <a href="{{ route('workspaces.folders.show', [$workspaceId, $folder->id]) }}" class="tile-link">
                                    {{ $folder->name }}
                                </a>
                            </h3>
                            <p class="folder-card-materials">
                                <span>{{ $materialsDesc }}</span>
                            </p>
                        </div>
                        <div class="folder-card-foot">
                            <x-button size="sm" variant="primary" icon="play" wire:click="studyFolder('{{ $folder->id }}')" aria-label="Study {{ $folder->name }}" class="relative z-10 btn-sm">
                                Study
                            </x-button>
                            <span class="folder-card-open" aria-hidden="true">
                                <span>Open</span>
                                <x-icon name="chevron-right" class="size-3.5 transition-transform duration-150 group-hover:translate-x-0.5" />
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="empty-place">
                <span class="item-icon ws-colour-amber" aria-hidden="true"><x-icon name="folder" class="size-5" /></span>
                <p class="font-medium">No folders yet</p>
                <p class="text-sm text-fg-muted">Create folders for your lectures, lab sessions, or course units to organize files and notes.</p>
                <x-button icon="folder-plus" wire:click="newFolder('module', '{{ $place->id }}')">New folder</x-button>
            </div>
        @endif
    @elseif ($isModule && $tab === 'files')
        {{-- A module's Files tab: all files across the module (both general and session files) and links. NO folder tiles. --}}
        <div class="flex flex-wrap items-center gap-2">
            <x-button icon="upload" wire:click="uploadFiles('module', '{{ $place->id }}')">Upload files</x-button>
            <x-button icon="link" wire:click="newLink('module', '{{ $place->id }}')">Add a link</x-button>
            <button type="button" class="btn btn-secondary" x-on:click="toggleMode()" :aria-pressed="isSelecting ? 'true' : 'false'">
                <x-icon name="list-checks" class="size-4" />
                <span x-text="isSelecting ? 'Done' : 'Select'">Select</span>
            </button>
        </div>
        <div hidden aria-hidden="true">
            @foreach ($moduleFolders as $f)
                <x-ai-menu kind="folder" :id="$f->id" :label="$f->name" />
            @endforeach
        </div>
        @if ($moduleFiles !== [] || $moduleLinks !== [])
            <ul class="item-list" role="list" aria-label="Module files and links">
                @foreach ($moduleFiles as $file)
                    <li class="item-row" wire:key="file-{{ $file->id }}"
                        data-select-key="file:{{ $file->id }}"
                        :class="{ 'is-selected': isSelected('file:{{ $file->id }}') }"
                        x-on:click="handleRowClick($event, 'file:{{ $file->id }}')">
                        <x-selection-check key="file:{{ $file->id }}" label="Select {{ $file->fileName() }}" />
                        <span class="item-icon ws-colour-{{ $fileColours[$file->kind] ?? 'teal' }}" aria-hidden="true"><x-icon :name="$file->icon()" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <a href="{{ route('workspaces.files.show', [$file->workspaceId, $file->id]) }}" class="tile-link">{{ $file->fileName() }}</a>
                            <span class="item-meta flex flex-wrap items-center gap-1.5 mt-0.5">
                                <span>{{ $file->typeLabel() }} · {{ $file->humanSize() }}</span>
                                <span class="text-fg-subtle">·</span>
                                @if ($file->folderId !== null && isset($foldersById[$file->folderId]))
                                    <a href="{{ route('workspaces.folders.show', [$workspaceId, $file->folderId, 'tab' => 'files']) }}" class="badge badge-neutral no-underline relative z-10" title="Session folder">
                                        <x-icon name="folder" class="size-3" />
                                        <span>{{ $foldersById[$file->folderId]->name }}</span>
                                    </a>
                                @else
                                    <span class="badge badge-neutral" title="General module file">General</span>
                                @endif
                            </span>
                            @php
                                $read = $readStates[$file->id] ?? null;
                                $digest = $read['digest'] ?? null;
                            @endphp
                            @if (($read['state'] ?? null) === 'read' && $digest)
                                <details class="digest">
                                    <summary class="digest-chip"><x-icon name="circle-check" class="size-3.5" />Read</summary>
                                    <div class="digest-body">
                                        <p>{{ $digest->summary }}</p>
                                        @if ($digest->topics !== [])
                                            <p class="text-fg-muted">{{ implode(' · ', $digest->topics) }}</p>
                                        @endif
                                        @if ($digest->outline !== [])
                                            <ol>
                                                @foreach (array_slice($digest->outline, 0, 12) as $line)
                                                    <li>{{ $line['heading'] }} <span class="text-fg-subtle">· {{ $line['page'] }}</span></li>
                                                @endforeach
                                            </ol>
                                        @endif
                                    </div>
                                </details>
                            @elseif (($read['state'] ?? null) === 'reading')
                                <span class="digest"><span class="digest-chip" role="status"><x-icon name="loader-circle" class="size-3.5 animate-spin" />Reading…</span></span>
                            @elseif (($read['state'] ?? null) === 'unread' && $file->moduleId !== null && $file->kind !== 'image')
                                <span class="digest"><button type="button" class="digest-chip is-action" wire:click="readFile('{{ $file->id }}')" wire:loading.attr="aria-busy" wire:target="readFile('{{ $file->id }}')">Read now</button></span>
                            @endif
                        </span>
                        @if ($file->kind !== 'image')
                            <x-ai-menu kind="file" :id="$file->id" :label="$file->fileName()" />
                        @endif
                        @include('livewire.workspaces.partials.row-menu', ['id' => $file->id, 'label' => $file->fileName(), 'items' => [
                            ['Download', 'download', null, false, route('files.content', [$file->id, 'download' => 1])],
                            ['Rename', 'pencil', "renameFile('{$file->id}')", false],
                            ['Move to…', 'folder-input', "moveFile('{$file->id}')", false],
                            ['Move to trash', 'trash-2', "trashFile('{$file->id}')", false],
                        ]])
                    </li>
                @endforeach
                @foreach ($moduleLinks as $link)
                    <li class="item-row" wire:key="link-{{ $link->id }}"
                        data-select-key="link:{{ $link->id }}"
                        :class="{ 'is-selected': isSelected('link:{{ $link->id }}') }"
                        x-on:click="handleRowClick($event, 'link:{{ $link->id }}')">
                        <x-selection-check key="link:{{ $link->id }}" label="Select {{ $link->title }}" />
                        <span class="item-icon ws-colour-teal" aria-hidden="true"><x-icon name="link" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer" class="tile-link">{{ $link->title }}<span class="sr-only"> (opens in a new tab)</span></a>
                            <span class="item-meta flex flex-wrap items-center gap-1.5 mt-0.5">
                                <span>{{ $link->site() }}</span>
                                @if ($link->folderId !== null && isset($foldersById[$link->folderId]))
                                    <span class="text-fg-subtle">·</span>
                                    <a href="{{ route('workspaces.folders.show', [$workspaceId, $link->folderId, 'tab' => 'files']) }}" class="badge badge-neutral no-underline relative z-10" title="Session folder">
                                        <x-icon name="folder" class="size-3" />
                                        <span>{{ $foldersById[$link->folderId]->name }}</span>
                                    </a>
                                @endif
                            </span>
                        </span>
                        @include('livewire.workspaces.partials.row-menu', ['id' => $link->id, 'label' => $link->title, 'items' => [
                            ['Edit', 'pencil', "editLink('{$link->id}')", false],
                            ['Move to…', 'folder-input', "moveLink('{$link->id}')", false],
                            ['Delete', 'trash-2', "confirmDelete('link', '{$link->id}')", false],
                        ]])
                    </li>
                @endforeach
            </ul>
        @else
            <div class="empty-place">
                <span class="item-icon" aria-hidden="true"><x-icon name="file-text" class="size-5" /></span>
                <p class="font-medium">No files yet</p>
                <p class="text-sm text-fg-muted">Files uploaded to this module or any of its session folders will appear here.</p>
                <x-button icon="upload" wire:click="uploadFiles('module', '{{ $place->id }}')">Upload files</x-button>
            </div>
        @endif
    @elseif ($isModule && $tab === 'notes')
        {{-- A module's Notes tab: all notes across the module (both general and session notes). NO folder tiles. --}}
        <div class="flex flex-wrap items-center gap-2">
            <x-button icon="file-plus" wire:click="newNote('module', '{{ $place->id }}')">New note</x-button>
            <button type="button" class="btn btn-secondary" x-on:click="toggleMode()" :aria-pressed="isSelecting ? 'true' : 'false'">
                <x-icon name="list-checks" class="size-4" />
                <span x-text="isSelecting ? 'Done' : 'Select'">Select</span>
            </button>
        </div>
        @if ($moduleNotes !== [])
            <ul class="item-list" role="list" aria-label="Module notes">
                @foreach ($moduleNotes as $note)
                    <li class="item-row" wire:key="note-{{ $note->id }}"
                        data-select-key="note:{{ $note->id }}"
                        :class="{ 'is-selected': isSelected('note:{{ $note->id }}') }"
                        x-on:click="handleRowClick($event, 'note:{{ $note->id }}')">
                        <x-selection-check key="note:{{ $note->id }}" label="Select {{ $note->displayTitle() }}" />
                        <span class="item-icon" aria-hidden="true"><x-icon name="file-text" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <a href="{{ route('workspaces.notes.show', [$note->workspaceId, $note->id]) }}" class="tile-link">{{ $note->displayTitle() }}</a>
                            <span class="item-meta flex flex-wrap items-center gap-1.5 mt-0.5">
                                <span>Note · {{ Carbon::parse($note->updatedAt)->diffForHumans() }}</span>
                                <span class="text-fg-subtle">·</span>
                                @if ($note->folderId !== null && isset($foldersById[$note->folderId]))
                                    <a href="{{ route('workspaces.folders.show', [$workspaceId, $note->folderId, 'tab' => 'notes']) }}" class="badge badge-neutral no-underline relative z-10" title="Session folder">
                                        <x-icon name="folder" class="size-3" />
                                        <span>{{ $foldersById[$note->folderId]->name }}</span>
                                    </a>
                                @else
                                    <span class="badge badge-neutral" title="General module note">General</span>
                                @endif
                            </span>
                        </span>
                        <x-ai-menu kind="note" :id="$note->id" :label="$note->displayTitle()" />
                        @include('livewire.workspaces.partials.row-menu', ['id' => $note->id, 'label' => $note->displayTitle(), 'items' => [
                            ['Open in a new window', 'picture-in-picture-2', null, false, route('workspaces.notes.show', [$note->workspaceId, $note->id, 'window' => 1]), "vistud-note-{$note->id}"],
                            $note->isPinned() ? ['Unpin', 'pin-off', "unpinNote('{$note->id}')", false] : ['Pin to the corner', 'pin', "pinNote('{$note->id}')", false],
                            ['Move to…', 'folder-input', "moveNote('{$note->id}')", false],
                            ['Move to trash', 'trash-2', "trashNote('{$note->id}')", false],
                        ]])
                    </li>
                @endforeach
            </ul>
        @else
            <div class="empty-place">
                <span class="item-icon" aria-hidden="true"><x-icon name="notebook-pen" class="size-5" /></span>
                <p class="font-medium">No notes yet</p>
                <p class="text-sm text-fg-muted">Notes created in this module or any of its session folders will appear here.</p>
                <x-button icon="file-plus" wire:click="newNote('module', '{{ $place->id }}')">New note</x-button>
            </div>
        @endif
    @elseif (! $isModule && $studyPlace && $tab === 'dashboard')
        {{-- Session Dashboard: comprehensive overview of this class/lab session --}}
        <div class="session-dashboard space-y-6" aria-label="Session dashboard">
            {{-- KPI Stat Cards Grid --}}
            <div class="session-kpi-grid">
                {{-- 1. Topics Progress KPI --}}
                <a href="{{ route('workspaces.folders.show', [$workspaceId, $place->id, 'tab' => 'topics']) }}" class="session-kpi-card group no-underline">
                    <div class="session-kpi-head">
                        <span class="session-kpi-icon ws-colour-purple" aria-hidden="true">
                            <x-icon name="list-checks" class="size-4" />
                        </span>
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ count($placeTopics) > 0 && $understood === count($placeTopics) ? 'bg-success-subtle text-success' : 'bg-accent-subtle text-accent' }}">
                            {{ count($placeTopics) > 0 ? round(($understood / count($placeTopics)) * 100) : 0 }}%
                        </span>
                    </div>
                    <span class="session-kpi-value">{{ $understood }} / {{ count($placeTopics) }}</span>
                    <span class="session-kpi-label">Topics understood</span>
                    <div class="session-progress-bar-wrap mt-2" aria-hidden="true">
                        <div class="session-progress-bar-fill" style="width: {{ count($placeTopics) > 0 ? min(100, round(($understood / count($placeTopics)) * 100)) : 0 }}%;"></div>
                    </div>
                </a>

                {{-- 2. Study Time KPI --}}
                <div class="session-kpi-card">
                    <div class="session-kpi-head">
                        <span class="session-kpi-icon ws-colour-blue" aria-hidden="true">
                            <x-icon name="clock" class="size-4" />
                        </span>
                        <span class="text-xs font-medium text-fg-muted">
                            {{ $sessionsCount }} {{ \Illuminate\Support\Str::plural('session', $sessionsCount) }}
                        </span>
                    </div>
                    <span class="session-kpi-value">{{ \App\Study\SessionDetails::duration($studySeconds ?? 0) }}</span>
                    <span class="session-kpi-label">Time studied</span>
                    <span class="session-kpi-subtext">
                        <x-icon name="sparkles" class="size-3 text-accent" />
                        <span>{{ $sessionsCount > 0 ? 'Logged in this session' : 'Ready to start' }}</span>
                    </span>
                </div>

                {{-- 3. Files KPI --}}
                <a href="{{ route('workspaces.folders.show', [$workspaceId, $place->id, 'tab' => 'files']) }}" class="session-kpi-card group no-underline">
                    <div class="session-kpi-head">
                        <span class="session-kpi-icon ws-colour-teal" aria-hidden="true">
                            <x-icon name="file-text" class="size-4" />
                        </span>
                        <span class="text-xs text-accent font-medium group-hover:translate-x-0.5 transition-transform flex items-center gap-0.5">
                            <span>Open</span>
                            <x-icon name="chevron-right" class="size-3" />
                        </span>
                    </div>
                    <span class="session-kpi-value">{{ count($folderFiles ?? []) }}</span>
                    <span class="session-kpi-label">{{ \Illuminate\Support\Str::plural('File', count($folderFiles ?? [])) }}</span>
                    <span class="session-kpi-subtext">Slides, PDFs & lab files</span>
                </a>

                {{-- 4. Notes KPI --}}
                <a href="{{ route('workspaces.folders.show', [$workspaceId, $place->id, 'tab' => 'notes']) }}" class="session-kpi-card group no-underline">
                    <div class="session-kpi-head">
                        <span class="session-kpi-icon ws-colour-purple" aria-hidden="true">
                            <x-icon name="notebook-pen" class="size-4" />
                        </span>
                        <span class="text-xs text-accent font-medium group-hover:translate-x-0.5 transition-transform flex items-center gap-0.5">
                            <span>Open</span>
                            <x-icon name="chevron-right" class="size-3" />
                        </span>
                    </div>
                    <span class="session-kpi-value">{{ count($folderNotes ?? []) }}</span>
                    <span class="session-kpi-label">{{ \Illuminate\Support\Str::plural('Note', count($folderNotes ?? [])) }}</span>
                    <span class="session-kpi-subtext">Lecture notes & summaries</span>
                </a>

                {{-- 5. Questions KPI --}}
                <a href="{{ route('workspaces.folders.show', [$workspaceId, $place->id, 'tab' => 'questions']) }}" class="session-kpi-card group no-underline">
                    <div class="session-kpi-head">
                        <span class="session-kpi-icon ws-colour-amber" aria-hidden="true">
                            <x-icon name="circle-help" class="size-4" />
                        </span>
                        <span class="text-xs text-accent font-medium group-hover:translate-x-0.5 transition-transform flex items-center gap-0.5">
                            <span>Open</span>
                            <x-icon name="chevron-right" class="size-3" />
                        </span>
                    </div>
                    <span class="session-kpi-value">{{ count($asked ?? []) }}</span>
                    <span class="session-kpi-label">{{ \Illuminate\Support\Str::plural('Question', count($asked ?? [])) }}</span>
                    <span class="session-kpi-subtext">
                        @if (($questions['open'] ?? 0) > 0)
                            <span class="text-warning font-semibold">{{ $questions['open'] }} open</span>
                            @if (($questions['stuck'] ?? 0) > 0)
                                <span>·</span>
                                <span class="text-danger font-semibold">{{ $questions['stuck'] }} stuck</span>
                            @endif
                        @else
                            <span>All resolved</span>
                        @endif
                    </span>
                </a>

                {{-- 6. Flashcards KPI --}}
                <a href="{{ route('workspaces.folders.show', [$workspaceId, $place->id, 'tab' => 'cards']) }}" class="session-kpi-card group no-underline">
                    <div class="session-kpi-head">
                        <span class="session-kpi-icon ws-colour-pink" aria-hidden="true">
                            <x-icon name="gallery-vertical-end" class="size-4" />
                        </span>
                        <span class="text-xs text-accent font-medium group-hover:translate-x-0.5 transition-transform flex items-center gap-0.5">
                            <span>Open</span>
                            <x-icon name="chevron-right" class="size-3" />
                        </span>
                    </div>
                    <span class="session-kpi-value">{{ $cards['total'] ?? 0 }}</span>
                    <span class="session-kpi-label">{{ \Illuminate\Support\Str::plural('Flashcard', $cards['total'] ?? 0) }}</span>
                    <span class="session-kpi-subtext">
                        @if (($cards['due'] ?? 0) > 0)
                            <span class="text-accent font-semibold">{{ $cards['due'] }} due today</span>
                        @else
                            <span>None due</span>
                        @endif
                    </span>
                </a>
            </div>

            {{-- The main topics the AI chose from this part's files, to review before they are tracked. --}}
            @include('livewire.workspaces.partials.topic-review')

            {{-- Main Dashboard Layout: 2 Columns on Desktop, Stack on Mobile/Tablet --}}
            <div class="session-layout-grid">
                {{-- Column 1: Topics & Materials --}}
                <div class="space-y-6 min-w-0">
                    {{-- Topics & Learning Roadmap Panel --}}
                    <section class="session-panel" aria-labelledby="session-topics-heading">
                        <div class="session-panel-head">
                            <h2 id="session-topics-heading" class="session-panel-title">
                                <x-icon name="list-checks" class="size-5 text-accent" />
                                <span>Topic Progress</span>
                                <span class="badge badge-neutral text-xs">{{ count($placeTopics) }}</span>
                            </h2>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('workspaces.folders.show', [$workspaceId, $place->id, 'tab' => 'topics']) }}" class="btn btn-secondary btn-sm no-underline">
                                    <span>All topics</span>
                                    <x-icon name="arrow-right" class="size-3.5" />
                                </a>
                            </div>
                        </div>

                        {{-- Topics List or Empty State --}}
                        @if ($placeTopics === [])
                            @if ($suggestions !== [])
                                <p class="text-sm text-fg-muted">No topics yet: accept the main topics above to start tracking them.</p>
                            @else
                                <div class="empty-place py-6">
                                    <span class="item-icon" aria-hidden="true"><x-icon name="list-checks" class="size-5" /></span>
                                    <p class="font-medium">No topics yet</p>
                                    <p class="text-sm text-fg-muted">Put this part's files in Files: the AI reads them all and suggests the main topics. Or add one below.</p>
                                    <div class="flex flex-wrap items-center justify-center gap-2 mt-2">
                                        <x-button size="sm" variant="primary" icon="sparkles" wire:click="openChooseAgain" wire:loading.attr="aria-busy" wire:target="openChooseAgain" busy-label="Starting…">Find the main topics</x-button>
                                        <a href="{{ route('workspaces.folders.show', [$workspaceId, $place->id, 'tab' => 'files']) }}" class="btn btn-secondary btn-sm no-underline">
                                            <x-icon name="upload" class="size-4" />Go to Files
                                        </a>
                                    </div>
                                </div>
                            @endif
                        @else
                            <ul class="module-topic-list" role="list">
                                @foreach ($placeTopics as $t)
                                    @include('livewire.workspaces.partials.topic-row', ['t' => $t])
                                @endforeach
                            </ul>
                        @endif

                        {{-- Quick Add Topic Form with Robust Compose Interface --}}
                        <form wire:submit="addTopic" class="module-compose-wrap" novalidate
                            x-data="{
                                formatOpen: false,
                                resize() {
                                    const el = this.$refs.box;
                                    if (!el) return;
                                    el.style.height = 'auto';
                                    el.style.height = Math.min(el.scrollHeight, 220) + 'px';
                                },
                                wrap(before, after = before, placeholder = '') {
                                    const el = this.$refs.box;
                                    if (!el) return;
                                    const start = el.selectionStart ?? 0;
                                    const end = el.selectionEnd ?? 0;
                                    const val = el.value || '';
                                    const selected = val.substring(start, end) || placeholder;
                                    const replacement = before + selected + after;
                                    el.value = val.substring(0, start) + replacement + val.substring(end);
                                    el.dispatchEvent(new Event('input', { bubbles: true }));
                                    el.focus();
                                    const cursor = selected ? start + replacement.length : start + before.length;
                                    el.setSelectionRange(cursor, cursor);
                                    this.$nextTick(() => this.resize());
                                },
                                toggleList() {
                                    const el = this.$refs.box;
                                    if (!el) return;
                                    const val = el.value || '';
                                    const start = el.selectionStart ?? 0;
                                    const end = el.selectionEnd ?? 0;
                                    const prevNewline = val.lastIndexOf('\n', start - 1);
                                    const lineStart = prevNewline === -1 ? 0 : prevNewline + 1;
                                    const nextNewline = val.indexOf('\n', end);
                                    const lineEnd = nextNewline === -1 ? val.length : nextNewline;
                                    const block = val.slice(lineStart, lineEnd);
                                    const lines = block.split('\n');
                                    const allBulleted = lines.every((l) => /^(\s*)[-*]\s+/.test(l));
                                    let replacement;
                                    if (allBulleted) {
                                        replacement = lines.map((l) => l.replace(/^(\s*)[-*]\s+/, '$1')).join('\n');
                                    } else {
                                        replacement = lines.map((l) => (/^(\s*)[-*]\s+/.test(l) ? l : '- ' + l)).join('\n');
                                    }
                                    el.value = val.slice(0, lineStart) + replacement + val.slice(lineEnd);
                                    el.dispatchEvent(new Event('input', { bubbles: true }));
                                    el.focus();
                                    const newPos = lineStart + replacement.length;
                                    el.setSelectionRange(newPos, newPos);
                                    this.$nextTick(() => this.resize());
                                },
                                handleKeydown(event) {
                                    const el = this.$refs.box;
                                    if (!el) return;
                                    if (event.ctrlKey || event.metaKey) {
                                        if (event.key.toLowerCase() === 'b') {
                                            event.preventDefault();
                                            this.wrap('**', '**', 'bold text');
                                            return;
                                        }
                                        if (event.key.toLowerCase() === 'i') {
                                            event.preventDefault();
                                            this.wrap('*', '*', 'italic text');
                                            return;
                                        }
                                        if (event.key.toLowerCase() === 'e') {
                                            event.preventDefault();
                                            this.wrap('`', '`', 'code');
                                            return;
                                        }
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            $wire.addTopic();
                                            return;
                                        }
                                    }
                                    if (event.key === 'Enter') {
                                        const val = el.value || '';
                                        const selStart = el.selectionStart ?? 0;
                                        const selEnd = el.selectionEnd ?? 0;
                                        const prevNewline = val.lastIndexOf('\n', selStart - 1);
                                        const lineStart = prevNewline === -1 ? 0 : prevNewline + 1;
                                        const nextNewline = val.indexOf('\n', selStart);
                                        const lineEnd = nextNewline === -1 ? val.length : nextNewline;
                                        const currentLine = val.slice(lineStart, lineEnd);
                                        const listMatch = currentLine.match(/^(\s*)([-*]|\d+\.)\s*(.*)$/);
                                        if (listMatch) {
                                            const [full, indent, marker, lineText] = listMatch;
                                            if (lineText.trim() === '') {
                                                event.preventDefault();
                                                el.value = val.slice(0, lineStart) + val.slice(lineEnd);
                                                el.setSelectionRange(lineStart, lineStart);
                                                el.dispatchEvent(new Event('input', { bubbles: true }));
                                                this.$nextTick(() => this.resize());
                                                return;
                                            }
                                            if (event.shiftKey) {
                                                event.preventDefault();
                                                let nextMarker = marker;
                                                if (/^\d+\.$/.test(marker)) {
                                                    nextMarker = (parseInt(marker, 10) + 1) + '.';
                                                }
                                                const continuation = '\n' + indent + nextMarker + ' ';
                                                const before = val.slice(0, selStart);
                                                const after = val.slice(selEnd);
                                                el.value = before + continuation + after;
                                                const newPos = selStart + continuation.length;
                                                el.setSelectionRange(newPos, newPos);
                                                el.dispatchEvent(new Event('input', { bubbles: true }));
                                                this.$nextTick(() => this.resize());
                                                return;
                                            }
                                        }
                                        if (!event.shiftKey && !event.isComposing) {
                                            event.preventDefault();
                                            $wire.addTopic();
                                        }
                                    }
                                }
                            }"
                            x-init="
                                $nextTick(() => resize());
                                $watch('$wire.topicName', (val) => {
                                    if (!val) {
                                        $nextTick(() => {
                                            if ($refs.box) $refs.box.style.height = 'auto';
                                        });
                                    }
                                });
                            ">
                            <div class="chat-compose">
                                <label for="dash-topic-name" class="sr-only">Add a topic to {{ $placeName }}</label>
                                <textarea
                                    id="dash-topic-name"
                                    class="chat-box"
                                    rows="1"
                                    x-ref="box"
                                    wire:model="topicName"
                                    placeholder="Add a topic, like “Processes & Threads” (or paste multiple, one per line)…"
                                    @error('topicName') aria-invalid="true" aria-describedby="dash-topic-error" @enderror
                                    x-on:input="resize()"
                                    x-on:paste="$nextTick(() => resize())"
                                    x-on:keydown="handleKeydown($event)"></textarea>

                                <div class="chat-compose-bar">
                                    <div class="chat-compose-tools" role="toolbar" aria-label="Tools and formatting">
                                        <button type="button" class="chat-tool-btn" :class="{ 'is-active': formatOpen }" @click="formatOpen = !formatOpen" title="Formatting tools" aria-label="Formatting tools" :aria-expanded="formatOpen.toString()">
                                            <span class="font-semibold text-xs leading-none">Aa</span>
                                        </button>

                                        <div class="chat-format-group" x-show="formatOpen" x-cloak x-transition>
                                            <span class="chat-tool-divider" aria-hidden="true"></span>
                                            <button type="button" class="chat-tool-btn" x-on:click="wrap('**', '**', 'bold text')" title="Bold" aria-label="Bold">
                                                <x-icon name="bold" class="size-4" />
                                            </button>
                                            <button type="button" class="chat-tool-btn" x-on:click="wrap('*', '*', 'italic text')" title="Italic" aria-label="Italic">
                                                <x-icon name="italic" class="size-4" />
                                            </button>
                                            <button type="button" class="chat-tool-btn" x-on:click="wrap('`', '`', 'code')" title="Code" aria-label="Code">
                                                <x-icon name="code" class="size-4" />
                                            </button>
                                            <button type="button" class="chat-tool-btn" x-on:click="toggleList()" title="Bulleted list" aria-label="Bulleted list">
                                                <x-icon name="list" class="size-4" />
                                            </button>
                                            <button type="button" class="chat-tool-btn" x-on:click="wrap('$', '$', 'formula')" title="Formula" aria-label="Formula">
                                                <x-icon name="sigma" class="size-4" />
                                            </button>
                                        </div>

                                        @if (! $isModule)
                                            <button type="button" class="btn btn-ghost btn-sm flex items-center gap-1 text-xs text-accent" wire:click="openChooseAgain" wire:loading.attr="aria-busy" wire:target="openChooseAgain" title="The AI reads all the files and suggests the main topics">
                                                <x-icon name="sparkles" class="size-3.5" />
                                                <span>Find main topics</span>
                                            </button>
                                        @endif
                                    </div>

                                    <div class="chat-compose-actions">
                                        <span class="chat-shortcut-hint">Shift+Enter new line</span>
                                        <x-button type="submit" icon="plus" variant="primary" size="sm" wire:loading.attr="aria-busy" wire:target="addTopic">Add</x-button>
                                    </div>
                                </div>
                            </div>
                        </form>
                        @error('topicName') <p id="dash-topic-error" class="field-error mt-1">{{ $message }}</p> @enderror
                    </section>

                    {{-- Session Materials: Files & Notes 2-Col Grid --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {{-- Files Preview Card --}}
                        <div class="session-panel">
                            <div class="session-panel-head">
                                <h3 class="font-semibold text-fg flex items-center gap-2 text-sm">
                                    <x-icon name="file-text" class="size-4 text-accent" />
                                    <span>Files ({{ count($folderFiles ?? []) }})</span>
                                </h3>
                                <a href="{{ route('workspaces.folders.show', [$workspaceId, $place->id, 'tab' => 'files']) }}" class="text-xs text-accent font-medium hover:opacity-80 transition-opacity no-underline">
                                    View all
                                </a>
                            </div>
                            @if (($folderFiles ?? []) !== [])
                                <ul class="session-mini-list" role="list">
                                    @foreach (array_slice($folderFiles, 0, 3) as $file)
                                        <li>
                                            <a href="{{ route('workspaces.files.show', [$file->workspaceId, $file->id]) }}" class="session-mini-item group">
                                                <span class="item-icon ws-colour-{{ $fileColours[$file->kind] ?? 'teal' }} size-8 rounded-md shrink-0" aria-hidden="true">
                                                    <x-icon :name="$file->icon()" class="size-4" />
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-sm font-medium text-fg group-hover:text-accent transition-colors">{{ $file->fileName() }}</span>
                                                    <span class="block text-xs text-fg-muted">{{ $file->typeLabel() }} · {{ $file->humanSize() }}</span>
                                                </span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <div class="text-center py-4 text-xs text-fg-muted">
                                    <p>No files uploaded to this session yet.</p>
                                    <button type="button" class="btn btn-secondary btn-sm mt-2" wire:click="uploadFiles('folder', '{{ $place->id }}')">
                                        <x-icon name="upload" class="size-3.5" />Upload files
                                    </button>
                                </div>
                            @endif
                        </div>

                        {{-- Notes Preview Card --}}
                        <div class="session-panel">
                            <div class="session-panel-head">
                                <h3 class="font-semibold text-fg flex items-center gap-2 text-sm">
                                    <x-icon name="notebook-pen" class="size-4 text-accent" />
                                    <span>Notes ({{ count($folderNotes ?? []) }})</span>
                                </h3>
                                <a href="{{ route('workspaces.folders.show', [$workspaceId, $place->id, 'tab' => 'notes']) }}" class="text-xs text-accent font-medium hover:opacity-80 transition-opacity no-underline">
                                    View all
                                </a>
                            </div>
                            @if (($folderNotes ?? []) !== [])
                                <ul class="session-mini-list" role="list">
                                    @foreach (array_slice($folderNotes, 0, 3) as $note)
                                        <li>
                                            <a href="{{ route('workspaces.notes.show', [$note->workspaceId, $note->id]) }}" class="session-mini-item group">
                                                <span class="item-icon ws-colour-purple size-8 rounded-md shrink-0" aria-hidden="true">
                                                    <x-icon name="notebook-pen" class="size-4" />
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-sm font-medium text-fg group-hover:text-accent transition-colors">{{ $note->title }}</span>
                                                    <span class="block text-xs text-fg-muted">{{ \Carbon\CarbonImmutable::parse($note->updatedAt)->diffForHumans() }}</span>
                                                </span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <div class="text-center py-4 text-xs text-fg-muted">
                                    <p>No notes written in this session yet.</p>
                                    <button type="button" class="btn btn-secondary btn-sm mt-2" wire:click="newNote('folder', '{{ $place->id }}')">
                                        <x-icon name="file-plus" class="size-3.5" />New note
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Column 2: Study Launchpad & Activity --}}
                <div class="space-y-6 min-w-0">
                    {{-- Quick Study Launchpad --}}
                    <section class="session-panel" aria-labelledby="study-launchpad-heading">
                        <div class="session-panel-head">
                            <h2 id="study-launchpad-heading" class="session-panel-title">
                                <x-icon name="sparkles" class="size-5 text-accent" />
                                <span>Study This Session</span>
                            </h2>
                        </div>
                        <p class="text-xs text-fg-muted leading-relaxed">
                            Study the materials, slides, and notes in <strong class="text-fg">{{ $placeName }}</strong> with the AI tutor.
                        </p>
                        <div class="space-y-2 pt-1">
                            <x-button variant="primary" icon="play" wire:click="studyModule" class="w-full justify-center text-sm py-2.5 font-semibold">
                                Study whole session
                            </x-button>
                            <button type="button" class="btn btn-secondary w-full justify-center text-sm no-underline" wire:click="studyAsk('quiz')">
                                <x-icon name="circle-help" class="size-4" />
                                <span>Quiz me on this session</span>
                            </button>
                            <button type="button" class="btn btn-secondary w-full justify-center text-sm no-underline" wire:click="studyAsk('test')">
                                <x-icon name="clipboard-check" class="size-4" />
                                <span>Test me on this session</span>
                            </button>
                            @if (($cards['total'] ?? 0) > 0)
                                <a href="{{ route('workspaces.flashcards.review', [$workspaceId, 'folder' => $place->id]) }}" class="btn btn-secondary w-full justify-center text-sm no-underline">
                                    <x-icon name="gallery-vertical-end" class="size-4" />
                                    <span>Review flashcards ({{ $cards['due'] ?? 0 }} due)</span>
                                </a>
                            @endif
                        </div>
                    </section>

                    {{-- Recent Study Activity --}}
                    <section class="session-panel" aria-labelledby="session-activity-heading">
                        <div class="session-panel-head">
                            <h2 id="session-activity-heading" class="session-panel-title">
                                <x-icon name="clock" class="size-5 text-accent" />
                                <span>Study History</span>
                            </h2>
                            <span class="text-xs text-fg-muted font-medium">{{ $sessionsCount }} {{ \Illuminate\Support\Str::plural('session', $sessionsCount) }}</span>
                        </div>
                        @if (($folderSessions ?? []) !== [])
                            <ul class="session-mini-list" role="list">
                                @foreach (array_slice($folderSessions, 0, 4) as $s)
                                    <li>
                                        <a href="{{ route('workspaces.sessions.show', [$s->workspaceId, $s->id]) }}" class="session-mini-item group">
                                            <span class="item-icon ws-colour-blue size-8 rounded-md shrink-0" aria-hidden="true">
                                                <x-icon name="book-open" class="size-4" />
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="flex items-center justify-between gap-1">
                                                    <span class="text-xs font-semibold text-fg capitalize">{{ $s->mode }} session</span>
                                                    <span class="text-xs font-medium text-accent">{{ \App\Study\SessionDetails::duration($s->studySeconds) }}</span>
                                                </span>
                                                <span class="block text-xs text-fg-muted mt-0.5">{{ \Carbon\CarbonImmutable::parse($s->startedAt)->diffForHumans() }}</span>
                                            </span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <div class="text-center py-6 text-xs text-fg-muted">
                                <x-icon name="clock" class="size-6 mx-auto mb-1 text-fg-subtle opacity-50" />
                                <p>No study sessions recorded yet for this session.</p>
                                <p class="mt-1">Click "Study whole session" above to start!</p>
                            </div>
                        @endif
                    </section>
                </div>
            </div>
        </div>
    @elseif ($studyPlace && $tab === 'topics')
        {{-- The main topics the AI chose from the files, to review before they are tracked. --}}
        <div class="mb-5">
            @include('livewire.workspaces.partials.topic-review')
        </div>

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
                                <span class="item-icon ws-colour-amber shrink-0" aria-hidden="true"><x-icon :name="ModuleParts::icon($folder->kind)" class="size-5" /></span>
                                <div class="topic-group-text">
                                    <h3 class="topic-group-title"><a href="{{ route('workspaces.folders.show', [$workspaceId, $folder->id]) }}">{{ $folder->name }}</a></h3>
                                    <p class="text-sm text-fg-muted">{{ $group['topics'] === [] ? 'No topics yet' : "{$done} of ".count($group['topics']).' understood' }}</p>
                                </div>
                                <div class="topic-group-actions">
                                    <x-button size="sm" variant="primary" icon="play" wire:click="studyFolder('{{ $folder->id }}')" aria-label="Study {{ $folder->name }}">Study</x-button>
                                </div>
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
                @if ($suggestions !== [])
                    <div class="empty-place border-dashed border-accent bg-accent-subtle py-8">
                        <span class="item-icon ws-colour-purple" aria-hidden="true"><x-icon name="sparkles" class="size-5" /></span>
                        <p class="font-semibold text-base">{{ count($suggestions) }} Suggested {{ count($suggestions) === 1 ? 'Topic' : 'Topics' }} Ready</p>
                        <p class="text-sm text-fg-muted max-w-md text-center">AI analyzed your session materials and prepared these concise topics. Accept them to begin tracking your study progress.</p>
                        <div class="flex flex-wrap items-center justify-center gap-2 mt-2">
                            <x-button size="sm" variant="primary" icon="plus" wire:click="addSuggested" wire:loading.attr="aria-busy" wire:target="addSuggested" busy-label="Adding…">Add All Suggested Topics ({{ count($suggestions) }})</x-button>
                            <x-button size="sm" variant="secondary" wire:click="pickSuggested">Pick Topics Individually</x-button>
                        </div>
                    </div>
                @else
                    <div class="empty-place">
                        <span class="item-icon" aria-hidden="true"><x-icon name="list-checks" class="size-5" /></span>
                        <p class="font-medium">No topics yet</p>
                        <p class="text-sm text-fg-muted">Put this part's files in Files: the AI reads them all and suggests the main topics. Or add one below.</p>
                        <div class="flex flex-wrap items-center justify-center gap-2 mt-2">
                            @if (! $isModule)
                                <x-button size="sm" variant="primary" icon="sparkles" wire:click="openChooseAgain" wire:loading.attr="aria-busy" wire:target="openChooseAgain" busy-label="Starting…">Find the main topics</x-button>
                                <a href="{{ route('workspaces.folders.show', [$workspaceId, $place->id, 'tab' => 'files']) }}" class="btn btn-secondary btn-sm no-underline"><x-icon name="upload" class="size-4" />Go to Files</a>
                            @else
                                <a href="{{ route('workspaces.modules.show', [$workspaceId, $place->id, 'tab' => 'files']) }}" class="btn btn-secondary btn-sm no-underline"><x-icon name="upload" class="size-4" />Go to Files</a>
                            @endif
                        </div>
                    </div>
                @endif
            @else
                <ul class="module-topic-list" role="list">
                    @foreach ($placeTopics as $t)
                        @include('livewire.workspaces.partials.topic-row', ['t' => $t])
                    @endforeach
                </ul>
            @endif
            <form wire:submit="addTopic" class="module-compose-wrap" novalidate
                x-data="{
                    formatOpen: false,
                    resize() {
                        const el = this.$refs.box;
                        if (!el) return;
                        el.style.height = 'auto';
                        el.style.height = Math.min(el.scrollHeight, 220) + 'px';
                    },
                    wrap(before, after = before, placeholder = '') {
                        const el = this.$refs.box;
                        if (!el) return;
                        const start = el.selectionStart ?? 0;
                        const end = el.selectionEnd ?? 0;
                        const val = el.value || '';
                        const selected = val.substring(start, end) || placeholder;
                        const replacement = before + selected + after;
                        el.value = val.substring(0, start) + replacement + val.substring(end);
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        el.focus();
                        const cursor = selected ? start + replacement.length : start + before.length;
                        el.setSelectionRange(cursor, cursor);
                        this.$nextTick(() => this.resize());
                    },
                    toggleList() {
                        const el = this.$refs.box;
                        if (!el) return;
                        const val = el.value || '';
                        const start = el.selectionStart ?? 0;
                        const end = el.selectionEnd ?? 0;
                        const prevNewline = val.lastIndexOf('\n', start - 1);
                        const lineStart = prevNewline === -1 ? 0 : prevNewline + 1;
                        const nextNewline = val.indexOf('\n', end);
                        const lineEnd = nextNewline === -1 ? val.length : nextNewline;
                        const block = val.slice(lineStart, lineEnd);
                        const lines = block.split('\n');
                        const allBulleted = lines.every((l) => /^(\s*)[-*]\s+/.test(l));
                        let replacement;
                        if (allBulleted) {
                            replacement = lines.map((l) => l.replace(/^(\s*)[-*]\s+/, '$1')).join('\n');
                        } else {
                            replacement = lines.map((l) => (/^(\s*)[-*]\s+/.test(l) ? l : '- ' + l)).join('\n');
                        }
                        el.value = val.slice(0, lineStart) + replacement + val.slice(lineEnd);
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        el.focus();
                        const newPos = lineStart + replacement.length;
                        el.setSelectionRange(newPos, newPos);
                        this.$nextTick(() => this.resize());
                    },
                    handleKeydown(event) {
                        const el = this.$refs.box;
                        if (!el) return;
                        if (event.ctrlKey || event.metaKey) {
                            if (event.key.toLowerCase() === 'b') {
                                event.preventDefault();
                                this.wrap('**', '**', 'bold text');
                                return;
                            }
                            if (event.key.toLowerCase() === 'i') {
                                event.preventDefault();
                                this.wrap('*', '*', 'italic text');
                                return;
                            }
                            if (event.key.toLowerCase() === 'e') {
                                event.preventDefault();
                                this.wrap('`', '`', 'code');
                                return;
                            }
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                $wire.addTopic();
                                return;
                            }
                        }
                        if (event.key === 'Enter') {
                            const val = el.value || '';
                            const selStart = el.selectionStart ?? 0;
                            const selEnd = el.selectionEnd ?? 0;
                            const prevNewline = val.lastIndexOf('\n', selStart - 1);
                            const lineStart = prevNewline === -1 ? 0 : prevNewline + 1;
                            const nextNewline = val.indexOf('\n', selStart);
                            const lineEnd = nextNewline === -1 ? val.length : nextNewline;
                            const currentLine = val.slice(lineStart, lineEnd);
                            const listMatch = currentLine.match(/^(\s*)([-*]|\d+\.)\s*(.*)$/);
                            if (listMatch) {
                                const [, indent, marker, lineText] = listMatch;
                                if (lineText.trim() === '') {
                                    event.preventDefault();
                                    el.value = val.slice(0, lineStart) + val.slice(lineEnd);
                                    el.setSelectionRange(lineStart, lineStart);
                                    el.dispatchEvent(new Event('input', { bubbles: true }));
                                    this.$nextTick(() => this.resize());
                                    return;
                                }
                                if (event.shiftKey) {
                                    event.preventDefault();
                                    let nextMarker = marker;
                                    if (/^\d+\.$/.test(marker)) {
                                        nextMarker = (parseInt(marker, 10) + 1) + '.';
                                    }
                                    const continuation = '\n' + indent + nextMarker + ' ';
                                    const before = val.slice(0, selStart);
                                    const after = val.slice(selEnd);
                                    el.value = before + continuation + after;
                                    const newPos = selStart + continuation.length;
                                    el.setSelectionRange(newPos, newPos);
                                    el.dispatchEvent(new Event('input', { bubbles: true }));
                                    this.$nextTick(() => this.resize());
                                    return;
                                }
                            }
                            if (!event.shiftKey && !event.isComposing) {
                                event.preventDefault();
                                $wire.addTopic();
                            }
                        }
                    }
                }"
                x-init="
                    $nextTick(() => resize());
                    $watch('$wire.topicName', (val) => {
                        if (!val) {
                            $nextTick(() => {
                                if ($refs.box) $refs.box.style.height = 'auto';
                            });
                        }
                    });
                ">
                <div class="chat-compose">
                    <label for="module-topic-name" class="sr-only">Add a topic to {{ $placeName }}</label>
                    <textarea
                        id="module-topic-name"
                        class="chat-box"
                        rows="1"
                        x-ref="box"
                        wire:model="topicName"
                        placeholder="Add a topic, like “CPU scheduling” (or paste multiple, one per line)…"
                        @error('topicName') aria-invalid="true" aria-describedby="module-topic-error" @enderror
                        x-on:input="resize()"
                        x-on:paste="$nextTick(() => resize())"
                        x-on:keydown="handleKeydown($event)"></textarea>

                    <div class="chat-compose-bar">
                        <div class="chat-compose-tools" role="toolbar" aria-label="Tools and formatting">
                            <button type="button" class="chat-tool-btn" :class="{ 'is-active': formatOpen }" @click="formatOpen = !formatOpen" title="Formatting tools" aria-label="Formatting tools" :aria-expanded="formatOpen.toString()">
                                <span class="font-semibold text-xs leading-none">Aa</span>
                            </button>

                            <div class="chat-format-group" x-show="formatOpen" x-cloak x-transition>
                                <span class="chat-tool-divider" aria-hidden="true"></span>
                                <button type="button" class="chat-tool-btn" x-on:click="wrap('**', '**', 'bold text')" title="Bold" aria-label="Bold">
                                    <x-icon name="bold" class="size-4" />
                                </button>
                                <button type="button" class="chat-tool-btn" x-on:click="wrap('*', '*', 'italic text')" title="Italic" aria-label="Italic">
                                    <x-icon name="italic" class="size-4" />
                                </button>
                                <button type="button" class="chat-tool-btn" x-on:click="wrap('`', '`', 'code')" title="Code" aria-label="Code">
                                    <x-icon name="code" class="size-4" />
                                </button>
                                <button type="button" class="chat-tool-btn" x-on:click="toggleList()" title="Bulleted list" aria-label="Bulleted list">
                                    <x-icon name="list" class="size-4" />
                                </button>
                                <button type="button" class="chat-tool-btn" x-on:click="wrap('$', '$', 'formula')" title="Formula" aria-label="Formula">
                                    <x-icon name="sigma" class="size-4" />
                                </button>
                            </div>

                            @if (! $isModule)
                                <button type="button" class="btn btn-ghost btn-sm flex items-center gap-1 text-xs text-accent" wire:click="openChooseAgain" wire:loading.attr="aria-busy" wire:target="openChooseAgain" title="The AI reads all the files and suggests the main topics">
                                    <x-icon name="sparkles" class="size-3.5" />
                                    <span>Find main topics</span>
                                </button>
                            @endif
                        </div>

                        <div class="chat-compose-actions">
                            <span class="chat-shortcut-hint">Shift+Enter new line</span>
                            <x-button type="submit" icon="plus" variant="primary" size="sm" wire:loading.attr="aria-busy" wire:target="addTopic">Add</x-button>
                        </div>
                    </div>
                </div>
            </form>
            @error('topicName') <p id="module-topic-error" class="field-error mt-1">{{ $message }}</p> @enderror
        </section>
    @elseif ($studyPlace && $tab === 'questions')
        {{-- A folder's questions: what the student doesn't get yet about what is in it. --}}
        <section class="space-y-3" aria-labelledby="folder-questions-heading">
            <h2 id="folder-questions-heading" class="sr-only">Questions</h2>
            <form wire:submit="askQuestion" class="module-compose-wrap" novalidate
                x-data="{
                    formatOpen: false,
                    resize() {
                        const el = this.$refs.box;
                        if (!el) return;
                        el.style.height = 'auto';
                        el.style.height = Math.min(el.scrollHeight, 220) + 'px';
                    },
                    wrap(before, after = before, placeholder = '') {
                        const el = this.$refs.box;
                        if (!el) return;
                        const start = el.selectionStart ?? 0;
                        const end = el.selectionEnd ?? 0;
                        const val = el.value || '';
                        const selected = val.substring(start, end) || placeholder;
                        const replacement = before + selected + after;
                        el.value = val.substring(0, start) + replacement + val.substring(end);
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        el.focus();
                        const cursor = selected ? start + replacement.length : start + before.length;
                        el.setSelectionRange(cursor, cursor);
                        this.$nextTick(() => this.resize());
                    },
                    toggleList() {
                        const el = this.$refs.box;
                        if (!el) return;
                        const val = el.value || '';
                        const start = el.selectionStart ?? 0;
                        const end = el.selectionEnd ?? 0;
                        const prevNewline = val.lastIndexOf('\n', start - 1);
                        const lineStart = prevNewline === -1 ? 0 : prevNewline + 1;
                        const nextNewline = val.indexOf('\n', end);
                        const lineEnd = nextNewline === -1 ? val.length : nextNewline;
                        const block = val.slice(lineStart, lineEnd);
                        const lines = block.split('\n');
                        const allBulleted = lines.every((l) => /^(\s*)[-*]\s+/.test(l));
                        let replacement;
                        if (allBulleted) {
                            replacement = lines.map((l) => l.replace(/^(\s*)[-*]\s+/, '$1')).join('\n');
                        } else {
                            replacement = lines.map((l) => (/^(\s*)[-*]\s+/.test(l) ? l : '- ' + l)).join('\n');
                        }
                        el.value = val.slice(0, lineStart) + replacement + val.slice(lineEnd);
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        el.focus();
                        const newPos = lineStart + replacement.length;
                        el.setSelectionRange(newPos, newPos);
                        this.$nextTick(() => this.resize());
                    },
                    handleKeydown(event) {
                        const el = this.$refs.box;
                        if (!el) return;
                        if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
                            event.preventDefault();
                            $wire.askQuestion();
                            return;
                        }
                        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                            event.preventDefault();
                            $wire.askQuestion();
                        }
                    }
                }"
                x-init="
                    $nextTick(() => resize());
                    $watch('$wire.questionText', (val) => {
                        if (!val) {
                            $nextTick(() => {
                                if ($refs.box) $refs.box.style.height = 'auto';
                            });
                        }
                    });
                ">
                <div class="chat-compose">
                    <label for="folder-question-text" class="sr-only">Ask a question about {{ $placeName }}</label>
                    <textarea
                        id="folder-question-text"
                        class="chat-box"
                        rows="1"
                        x-ref="box"
                        wire:model="questionText"
                        maxlength="1000"
                        placeholder="Something you don't get yet… (Enter to add, Shift+Enter for new line)"
                        @error('questionText') aria-invalid="true" aria-describedby="folder-question-error" @enderror
                        x-on:input="resize()"
                        x-on:paste="$nextTick(() => resize())"
                        x-on:keydown="handleKeydown($event)"></textarea>

                    <div class="chat-compose-bar">
                        <div class="chat-compose-tools" role="toolbar" aria-label="Tools and formatting">
                            <button type="button" class="chat-tool-btn" :class="{ 'is-active': formatOpen }" @click="formatOpen = !formatOpen" title="Formatting tools" aria-label="Formatting tools" :aria-expanded="formatOpen.toString()">
                                <span class="font-semibold text-xs leading-none">Aa</span>
                            </button>

                            <div class="chat-format-group" x-show="formatOpen" x-cloak x-transition>
                                <span class="chat-tool-divider" aria-hidden="true"></span>
                                <button type="button" class="chat-tool-btn" x-on:click="wrap('**', '**', 'bold text')" title="Bold" aria-label="Bold"><x-icon name="bold" class="size-4" /></button>
                                <button type="button" class="chat-tool-btn" x-on:click="wrap('*', '*', 'italic text')" title="Italic" aria-label="Italic"><x-icon name="italic" class="size-4" /></button>
                                <button type="button" class="chat-tool-btn" x-on:click="wrap('`', '`', 'code')" title="Code" aria-label="Code"><x-icon name="code" class="size-4" /></button>
                                <button type="button" class="chat-tool-btn" x-on:click="toggleList()" title="Bulleted list" aria-label="Bulleted list"><x-icon name="list" class="size-4" /></button>
                                <button type="button" class="chat-tool-btn" x-on:click="wrap('$', '$', 'formula')" title="Formula" aria-label="Formula"><x-icon name="sigma" class="size-4" /></button>
                            </div>
                        </div>

                        <div class="chat-compose-actions">
                            <span class="chat-shortcut-hint">Shift+Enter new line</span>
                            <x-button type="submit" icon="plus" variant="primary" size="sm" wire:loading.attr="aria-busy" wire:target="askQuestion">Add</x-button>
                        </div>
                    </div>
                </div>
            </form>
            @error('questionText') <p id="folder-question-error" class="field-error mt-1">{{ $message }}</p> @enderror
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
