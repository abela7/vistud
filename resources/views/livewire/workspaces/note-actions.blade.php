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
        @php
            $downloads = ['pdf' => ['Download as PDF', 'file-text'], 'docx' => ['Download as Word', 'file-type'], 'md' => ['Download as Markdown', 'file-code'], 'txt' => ['Download as text', 'file']];
            $items = array_map(fn ($format) => [$downloads[$format][0], $downloads[$format][1], null, false, route('workspaces.notes.export', [$note->workspaceId, $note->id, $format])], \App\Study\NoteExports::available());
        @endphp
        @include('livewire.workspaces.partials.row-menu', ['id' => 'note-'.$note->id, 'label' => $note->displayTitle(), 'items' => [
            ...$items,
            ['Move to trash', 'trash-2', 'trash', false],
        ]])
    @endif
    <x-toast :message="$notice" :tone="$noticeTone" />
</div>
