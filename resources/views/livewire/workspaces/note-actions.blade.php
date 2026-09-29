{{-- The note page's actions (App\Livewire\Workspaces\NoteActions). --}}
<div class="flex flex-wrap items-center gap-2">
    @if ($note->trashedAt !== null)
        <x-button variant="primary" icon="rotate-ccw" wire:click="restore">Restore</x-button>
    @else
        {{-- Pinned: a button for this note in the corner of every page. --}}
        <button type="button" class="btn btn-ghost note-mode" wire:click="togglePin" title="{{ $note->isPinned() ? 'Unpin: take its button out of the corner of every page' : 'Pin: keep a button for this note in the corner of every page' }}">
            <span>
                <x-icon :name="$note->isPinned() ? 'pin-off' : 'pin'" class="size-4" />
                <span class="max-sm:sr-only">{{ $note->isPinned() ? 'Unpin' : 'Pin' }}</span>
            </span>
        </button>
        @include('livewire.workspaces.partials.row-menu', ['id' => 'note-'.$note->id, 'label' => $note->displayTitle(), 'items' => [
            ['Download as Markdown', 'download', null, false, route('workspaces.notes.export', [$note->workspaceId, $note->id, 'md'])],
            ['Download as text', 'file-text', null, false, route('workspaces.notes.export', [$note->workspaceId, $note->id, 'txt'])],
            ['Move to trash', 'trash-2', 'trash', false],
        ]])
    @endif
    <x-toast :message="$notice" :tone="$noticeTone" />
</div>
