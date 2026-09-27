{{--
    The instructions a study session starts from (App\Livewire\Workspaces\Instructions):
    about the student, and for this course, in one dialog.
--}}
<div>
    <div role="status" aria-live="polite" class="empty:hidden">
        @if ($notice)
            <x-alert tone="success" :live="false">{{ $notice }}</x-alert>
        @endif
    </div>

    <dialog id="instructions-dialog" class="modal" aria-labelledby="instructions-dialog-title"
        wire:ignore.self
        x-data
        x-on:instructions-dialog-open.window="$el.open || $el.showModal()"
        x-on:instructions-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.editing && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($editing)
            <form wire:submit="save" novalidate class="modal-panel" wire:key="instructions-form">
                <div class="modal-head">
                    <h2 id="instructions-dialog-title" class="min-w-0 flex-1 text-lg font-semibold">Instructions for the AI</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>
                <div class="space-y-4 px-5 pt-2">
                    <div class="field">
                        <label for="instructions-me" class="field-label">About you <span class="font-normal text-fg-muted">· every course</span></label>
                        <textarea id="instructions-me" class="input" rows="4" maxlength="{{ \App\Study\Instructions::MAX_TEXT }}" wire:model="me" autofocus placeholder="I'm in my second year. Explain with everyday examples."></textarea>
                        @error('me') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label for="instructions-course" class="field-label">This course</label>
                        <textarea id="instructions-course" class="input" rows="4" maxlength="{{ \App\Study\Instructions::MAX_TEXT }}" wire:model="course" placeholder="Go slide by slide. Ask me two questions after each section."></textarea>
                        @error('course') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="modal-actions">
                    <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                    <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save</x-button>
                </div>
            </form>
        @endif
    </dialog>
</div>
