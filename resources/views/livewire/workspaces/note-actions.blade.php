{{-- The note page's actions (App\Livewire\Workspaces\NoteActions). --}}
<div>
    @if ($note->trashedAt !== null)
        <x-button variant="primary" icon="rotate-ccw" wire:click="restore">Restore</x-button>
    @else
        @include('livewire.workspaces.partials.row-menu', ['id' => 'note-'.$note->id, 'label' => $note->displayTitle(), 'items' => [
            ['Move to trash', 'trash-2', 'trash', false],
        ]])
    @endif
</div>
