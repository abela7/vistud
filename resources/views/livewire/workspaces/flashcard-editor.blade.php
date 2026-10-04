{{-- Writing, changing or deleting a flashcard (App\Livewire\Workspaces\FlashcardEditor). --}}
@php
    $headings = ['new' => 'New flashcard', 'edit' => 'Edit flashcard', 'delete' => 'Delete this flashcard?'];
@endphp
<div>
    <dialog id="flashcard-dialog" class="modal" aria-labelledby="flashcard-dialog-title"
        wire:ignore.self
        x-data
        x-on:flashcard-dialog-open.window="$el.open || $el.showModal()"
        x-on:flashcard-dialog-close.window="$el.open && $el.close()"
        x-on:flashcard-front-focus.window="$nextTick(() => document.getElementById('flashcard-front')?.focus())"
        x-on:close="$wire.mode && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($mode)
            <form wire:submit="save" novalidate class="modal-panel" wire:key="flashcard-{{ $mode }}-{{ $cardId }}">
                <div class="modal-head">
                    <h2 id="flashcard-dialog-title" class="min-w-0 flex-1 text-lg font-semibold" @if ($mode === 'delete') tabindex="-1" autofocus @endif>{{ $headings[$mode] }}</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>

                <div class="space-y-4 px-5 pt-2">
                    @if ($mode === 'delete')
                        <p class="break-words"><span class="font-medium">{{ $front }}</span></p>
                        <p class="text-fg-muted">The card is removed from your reviews. How you answered it stays in your study record, as what happened.</p>
                    @else
                        <div class="field">
                            <label for="flashcard-front" class="field-label">Front</label>
                            <textarea id="flashcard-front" class="input" rows="2" maxlength="{{ \App\Study\Flashcards::MAX_FRONT }}" wire:model="front" autofocus aria-describedby="flashcard-front-hint" @error('front') aria-invalid="true" @enderror></textarea>
                            <p id="flashcard-front-hint" class="field-hint">A question with one answer, like “What does a LEFT JOIN keep?”</p>
                            @error('front') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="field">
                            <label for="flashcard-back" class="field-label">Back</label>
                            <textarea id="flashcard-back" class="input" rows="3" maxlength="{{ \App\Study\Flashcards::MAX_BACK }}" wire:model="back" aria-describedby="flashcard-back-hint" @error('back') aria-invalid="true" @enderror></textarea>
                            <p id="flashcard-back-hint" class="field-hint">The answer, short: a sentence or two.</p>
                            @error('back') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="field">
                                <label for="flashcard-module" class="field-label">Module</label>
                                <select id="flashcard-module" class="input" wire:model.live="moduleId">
                                    <option value="">No module</option>
                                    @foreach ($modules as $module)
                                        <option value="{{ $module->id }}">{{ $module->title }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label for="flashcard-topic" class="field-label">Topic</label>
                                <select id="flashcard-topic" class="input" wire:model.live="topicId">
                                    <option value="">No topic</option>
                                    @foreach ($topics as $topic)
                                        <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        @if ($added > 0)
                            <p class="text-sm text-fg-muted" role="status">{{ $added === 1 ? '1 card added.' : $added.' cards added.' }} Write the next one, or close when you're done.</p>
                        @endif
                    @endif
                </div>

                <div class="modal-actions">
                    @if ($mode === 'delete')
                        <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                        <x-button type="submit" variant="danger" wire:loading.attr="aria-busy" wire:target="save" busy-label="Deleting…">Delete</x-button>
                    @elseif ($mode === 'new')
                        <x-button x-on:click="$el.closest('dialog').close()">{{ $added > 0 ? 'Done' : 'Cancel' }}</x-button>
                        <x-button wire:click="save(true)" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save and add another</x-button>
                        <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save</x-button>
                    @else
                        <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                        <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save</x-button>
                    @endif
                </div>
            </form>
        @endif
    </dialog>
</div>
