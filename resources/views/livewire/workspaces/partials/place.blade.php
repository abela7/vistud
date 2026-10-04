{{--
    What one place ($key: a module, a folder, or the top level) holds: its
    folders as tiles to open, then its notes, files and web links as rows.
    Each row is one link; its menu sits above it.
--}}
@php
    use Illuminate\Support\Carbon;

    // $show: all (a folder), files (a module's Files tab: folders, files and links) or notes (its Notes tab).
    $show = $show ?? 'all';
    $placeNotes = in_array($show, ['all', 'notes'], true) ? ($notesIn[$key] ?? []) : [];
    $placeFiles = in_array($show, ['all', 'files'], true) ? ($filesIn[$key] ?? []) : [];
    $placeLinks = in_array($show, ['all', 'files'], true) ? ($linksIn[$key] ?? []) : [];
    $placeFolders = in_array($show, ['all', 'files'], true) ? ($children[$key] ?? []) : [];
    $fileColours = ['pdf' => 'red', 'document' => 'blue', 'slides' => 'orange', 'spreadsheet' => 'green', 'text' => 'pink', 'image' => 'purple'];
@endphp
@if ($placeFolders !== [])
    <ul class="folder-grid" role="list" aria-label="Folders">
        @foreach ($placeFolders as $i => $folder)
            @php $inside = $itemCounts[$folder->id] ?? 0; @endphp
            <li class="folder-tile" wire:key="folder-{{ $folder->id }}"
                data-select-key="folder:{{ $folder->id }}"
                :class="{ 'is-selected': isSelected('folder:{{ $folder->id }}') }"
                x-on:click="handleRowClick($event, 'folder:{{ $folder->id }}')">
                <x-selection-check key="folder:{{ $folder->id }}" label="Select {{ $folder->name }}" />
                <span class="item-icon ws-colour-amber" aria-hidden="true"><x-icon name="folder" class="size-5" /></span>
                <span class="min-w-0 flex-1">
                    <a href="{{ route('workspaces.folders.show', [$workspaceId, $folder->id]) }}" class="tile-link">{{ $folder->name }}</a>
                    <span class="item-meta">{{ $inside === 0 ? 'Empty' : ($inside === 1 ? '1 item' : $inside.' items') }}</span>
                </span>
                <x-ai-menu kind="folder" :id="$folder->id" :label="$folder->name" />
                @include('livewire.workspaces.partials.row-menu', ['id' => $folder->id, 'label' => $folder->name, 'items' => [
                    ['Rename', 'pencil', "renameFolder('{$folder->id}')", false],
                    ['Move to…', 'folder-input', "moveFolder('{$folder->id}')", false],
                    ['Move up', 'arrow-up', "moveFolderBy('{$folder->id}', -1)", $i === 0],
                    ['Move down', 'arrow-down', "moveFolderBy('{$folder->id}', 1)", $i === count($placeFolders) - 1],
                    ['Delete', 'trash-2', "confirmDelete('folder', '{$folder->id}')", false],
                ]])
            </li>
        @endforeach
    </ul>
@endif

@if ($placeNotes !== [] || $placeFiles !== [] || $placeLinks !== [])
    <ul class="item-list" role="list" aria-label="Notes, files and links">
        @foreach ($placeNotes as $note)
            <li class="item-row" wire:key="note-{{ $note->id }}"
                data-select-key="note:{{ $note->id }}"
                :class="{ 'is-selected': isSelected('note:{{ $note->id }}') }"
                x-on:click="handleRowClick($event, 'note:{{ $note->id }}')">
                <x-selection-check key="note:{{ $note->id }}" label="Select {{ $note->displayTitle() }}" />
                <span class="item-icon" aria-hidden="true"><x-icon name="file-text" class="size-5" /></span>
                <span class="min-w-0 flex-1">
                    <a href="{{ route('workspaces.notes.show', [$note->workspaceId, $note->id]) }}" class="tile-link">{{ $note->displayTitle() }}</a>
                    <span class="item-meta">Note · {{ Carbon::parse($note->updatedAt)->diffForHumans() }}</span>
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
        @foreach ($placeFiles as $file)
            <li class="item-row" wire:key="file-{{ $file->id }}"
                data-select-key="file:{{ $file->id }}"
                :class="{ 'is-selected': isSelected('file:{{ $file->id }}') }"
                x-on:click="handleRowClick($event, 'file:{{ $file->id }}')">
                <x-selection-check key="file:{{ $file->id }}" label="Select {{ $file->fileName() }}" />
                <span class="item-icon ws-colour-{{ $fileColours[$file->kind] ?? 'teal' }}" aria-hidden="true"><x-icon :name="$file->icon()" class="size-5" /></span>
                <span class="min-w-0 flex-1">
                    <a href="{{ route('workspaces.files.show', [$file->workspaceId, $file->id]) }}" class="tile-link">{{ $file->fileName() }}</a>
                    <span class="item-meta">{{ $file->typeLabel() }} · {{ $file->humanSize() }}</span>
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
        @foreach ($placeLinks as $link)
            <li class="item-row" wire:key="link-{{ $link->id }}"
                data-select-key="link:{{ $link->id }}"
                :class="{ 'is-selected': isSelected('link:{{ $link->id }}') }"
                x-on:click="handleRowClick($event, 'link:{{ $link->id }}')">
                <x-selection-check key="link:{{ $link->id }}" label="Select {{ $link->title }}" />
                <span class="item-icon ws-colour-teal" aria-hidden="true"><x-icon name="link" class="size-5" /></span>
                <span class="min-w-0 flex-1">
                    <a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer" class="tile-link">{{ $link->title }}<span class="sr-only"> (opens in a new tab)</span></a>
                    <span class="item-meta">{{ $link->site() }}</span>
                </span>
                @include('livewire.workspaces.partials.row-menu', ['id' => $link->id, 'label' => $link->title, 'items' => [
                    ['Edit', 'pencil', "editLink('{$link->id}')", false],
                    ['Move to…', 'folder-input', "moveLink('{$link->id}')", false],
                    ['Delete', 'trash-2', "confirmDelete('link', '{$link->id}')", false],
                ]])
            </li>
        @endforeach
    </ul>
@endif

@if ($placeFolders === [] && $placeNotes === [] && $placeFiles === [] && $placeLinks === [])
    <div class="empty-place">
        <span class="item-icon" aria-hidden="true"><x-icon name="{{ $show === 'notes' ? 'file-text' : 'folder-open' }}" class="size-5" /></span>
        <p class="font-medium">{{ $show === 'notes' ? 'No notes yet' : ($show === 'files' ? 'No files yet' : 'Nothing here yet') }}</p>
        <div class="flex flex-wrap justify-center gap-2">
            @if ($show === 'notes')
                <x-button icon="file-plus" wire:click="newNote('{{ $placeType }}', '{{ $placeId }}')">New note</x-button>
            @else
                <x-button icon="upload" wire:click="uploadFiles('{{ $placeType }}', '{{ $placeId }}')">Upload files</x-button>
            @endif
        </div>
    </div>
@endif
