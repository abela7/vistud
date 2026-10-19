{{-- The panel under an app that asks how far back to take its work back (Livewire\Study\Connections::undo). --}}
<li class="space-y-2 rounded-lg border border-line p-3 text-sm" data-test="undo-panel" wire:key="undo-{{ $connection->id }}">
    <div class="field">
        <label for="undo-window" class="field-label">Take back what this app saved in</label>
        <select id="undo-window" class="select" wire:model.live="undoWindow">
            @foreach ($windows as $key => $label)
                <option value="{{ $key }}">{{ ucfirst($label) }}</option>
            @endforeach
        </select>
    </div>
    @if ($undoPlan)
        <p>This takes back: {{ $undoPlan }}. Quizzes are deleted and their answers stop counting. A topic mark you changed yourself stays.</p>
        <p class="text-fg-muted">Not taken back: topics it added, notes it wrote (each note keeps its earlier versions), flashcard reviews, and what it logged on assignments.</p>
        <div class="flex flex-wrap gap-2">
            <x-button variant="danger" size="sm" wire:click="undo">Take it back</x-button>
            <x-button variant="ghost" size="sm" wire:click="cancelUndo">Cancel</x-button>
        </div>
    @else
        <p class="text-fg-muted">Nothing to take back in that time.</p>
        <x-button variant="ghost" size="sm" wire:click="cancelUndo">Close</x-button>
    @endif
</li>
