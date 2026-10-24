{{-- The file page's actions (App\Livewire\Workspaces\FileActions). --}}
<div>
    @if ($file->trashedAt !== null)
        <x-button variant="primary" icon="rotate-ccw" wire:click="restore">Restore</x-button>
    @else
        @if ($file->kind !== 'image')
            {{-- Nothing goes to the AI until a choice here is made (the owner's ask, 2026-10-24). --}}
            <x-ai-menu kind="file" :id="$file->id" :label="$file->fileName()" text="Ask the AI" />
        @endif
        @include('livewire.workspaces.partials.row-menu', ['id' => 'file-'.$file->id, 'label' => $file->fileName(), 'items' => [
            ['Move to trash', 'trash-2', 'trash', false],
        ]])
    @endif
</div>
