{{-- The folders in one module or folder ($key), each with its own folders below it. --}}
@php $list = $children[$key] ?? []; @endphp
@if ($list !== [])
    <ul class="folder-list" role="list">
        @foreach ($list as $i => $folder)
            <li wire:key="folder-{{ $folder->id }}">
                <div class="folder-row">
                    <x-icon name="folder" class="size-5 shrink-0 text-fg-muted" />
                    <span class="min-w-0 flex-1 break-words">{{ $folder->name }}</span>
                    @include('livewire.workspaces.partials.row-menu', ['id' => $folder->id, 'label' => $folder->name, 'items' => [
                        ['Rename', 'pencil', "renameFolder('{$folder->id}')", false],
                        ['New folder inside', 'folder-plus', "newFolder('folder', '{$folder->id}')", $folder->depth >= \App\Study\Folders::MAX_DEPTH],
                        ['Move to…', 'folder-input', "moveFolder('{$folder->id}')", false],
                        ['Move up', 'arrow-up', "moveFolderBy('{$folder->id}', -1)", $i === 0],
                        ['Move down', 'arrow-down', "moveFolderBy('{$folder->id}', 1)", $i === count($list) - 1],
                        ['Delete', 'trash-2', "confirmDelete('folder', '{$folder->id}')", false],
                    ]])
                </div>
                @include('livewire.workspaces.partials.folder-list', ['key' => "folder:{$folder->id}"])
            </li>
        @endforeach
    </ul>
@endif
