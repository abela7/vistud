{{--
    What one place ($key: a module, a folder, or the top level) holds: its
    notes, its files, then its folders, each folder with what it holds below it.
--}}
@php
    $placeNotes = $notesIn[$key] ?? [];
    $placeFiles = $filesIn[$key] ?? [];
    $placeFolders = $children[$key] ?? [];
@endphp
@if ($placeNotes !== [] || $placeFiles !== [] || $placeFolders !== [])
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
        @foreach ($placeFiles as $file)
            <li wire:key="file-{{ $file->id }}">
                <div class="folder-row">
                    <x-icon :name="$file->icon()" class="size-5 shrink-0 text-fg-muted" />
                    <span class="min-w-0 flex-1 py-1">
                        <a href="{{ route('workspaces.files.show', [$file->workspaceId, $file->id]) }}" class="item-link">{{ $file->fileName() }}</a>
                        <span class="block text-sm text-fg-muted">{{ $file->typeLabel() }} · {{ $file->humanSize() }}</span>
                    </span>
                    @include('livewire.workspaces.partials.row-menu', ['id' => $file->id, 'label' => $file->fileName(), 'items' => [
                        ['Download', 'download', null, false, route('files.content', [$file->id, 'download' => 1])],
                        ['Rename', 'pencil', "renameFile('{$file->id}')", false],
                        ['Move to…', 'folder-input', "moveFile('{$file->id}')", false],
                        ['Move to trash', 'trash-2', "trashFile('{$file->id}')", false],
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
                        ['Upload files here', 'upload', "uploadFiles('folder', '{$folder->id}')", false],
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
