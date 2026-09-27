{{--
    A place's "New" button: a note, files, a link or a folder in it
    ($placeType and $placeId). $folders is false where no folder may go.
--}}
<div class="relative shrink-0">
    <button type="button" @class(['btn', 'btn-primary' => $primary ?? false, 'btn-secondary' => ! ($primary ?? false)]) wire:ignore.self data-menu-button aria-controls="new-menu" aria-expanded="false">
        <span class="btn-idle"><x-icon name="plus" class="size-4" />New</span>
    </button>
    <div id="new-menu" class="row-menu" wire:ignore.self data-menu-panel hidden>
        <button type="button" class="menu-item" wire:click="newNote('{{ $placeType }}', '{{ $placeId }}')"><x-icon name="file-plus" class="size-4" />Note</button>
        <button type="button" class="menu-item" wire:click="uploadFiles('{{ $placeType }}', '{{ $placeId }}')"><x-icon name="upload" class="size-4" />Upload files</button>
        <button type="button" class="menu-item" wire:click="newLink('{{ $placeType }}', '{{ $placeId }}')"><x-icon name="link" class="size-4" />Link</button>
        <button type="button" class="menu-item" wire:click="newFolder('{{ $placeType }}', '{{ $placeId }}')" @disabled(! ($folders ?? true))><x-icon name="folder-plus" class="size-4" />Folder</button>
    </div>
</div>
