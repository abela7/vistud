{{-- The file page's actions (App\Livewire\Workspaces\FileActions). --}}
<div>
    @if ($file->trashedAt !== null)
        <x-button variant="primary" icon="rotate-ccw" wire:click="restore">Restore</x-button>
    @else
        @include('livewire.workspaces.partials.row-menu', ['id' => 'file-'.$file->id, 'label' => $file->fileName(), 'items' => [
            ['Move to trash', 'trash-2', 'trash', false],
        ]])
    @endif
</div>
