{{--
    What one place ($key: a module, a folder, or the top level) holds: its
    notes, then its folders, each folder with what it holds below it.
--}}
@php
    $placeNotes = $notesIn[$key] ?? [];
    $placeFolders = $children[$key] ?? [];
@endphp
@if ($placeNotes !== [] || $placeFolders !== [])
    <ul class="folder-list" role="list">
        @foreach ($placeNotes as $note)
            <li wire:key="note-{{ $note->id }}">
                <div class="folder-row">
                    <x-icon name="file-text" class="size-5 shrink-0 text-fg-muted" />
                    <span class="min-w-0 flex-1 py-1">
                        <a href="{{ route('workspaces.notes.show', [$note->workspaceId, $note->id]) }}" class="item-link">{{ $note->displayTitle() }}</a>
                        <span class="block text-sm text-fg-muted">Note · edited {{ \Illuminate\Support\Carbon::parse($note->updatedAt)->diffForHumans() }}</span>
                    </span>
                    @include('livewire.workspaces.partials.row-menu', ['id' => $note->id, 'label' => $note->displayTitle(), 'items' => [
                        ['Move to…', 'folder-input', "moveNote('{$note->id}')", false],
                        ['Move to trash', 'trash-2', "trashNote('{$note->id}')", false],
                    ]])
                </div>
            </li>
        @endforeach
        @foreach ($placeFolders as $i => $folder)
            <li wire:key="folder-{{ $folder->id }}">
                <div class="folder-row">
                    <x-icon name="folder" class="size-5 shrink-0 text-fg-muted" />
                    <span class="min-w-0 flex-1 break-words">{{ $folder->name }}</span>
                    @include('livewire.workspaces.partials.row-menu', ['id' => $folder->id, 'label' => $folder->name, 'items' => [
                        ['New note inside', 'file-plus', "newNote('folder', '{$folder->id}')", false],
                        ['New folder inside', 'folder-plus', "newFolder('folder', '{$folder->id}')", $folder->depth >= \App\Study\Folders::MAX_DEPTH],
                        ['Rename', 'pencil', "renameFolder('{$folder->id}')", false],
                        ['Move to…', 'folder-input', "moveFolder('{$folder->id}')", false],
                        ['Move up', 'arrow-up', "moveFolderBy('{$folder->id}', -1)", $i === 0],
                        ['Move down', 'arrow-down', "moveFolderBy('{$folder->id}', 1)", $i === count($placeFolders) - 1],
                        ['Delete', 'trash-2', "confirmDelete('folder', '{$folder->id}')", false],
                    ]])
                </div>
                @include('livewire.workspaces.partials.place', ['key' => "folder:{$folder->id}"])
            </li>
        @endforeach
    </ul>
@endif
