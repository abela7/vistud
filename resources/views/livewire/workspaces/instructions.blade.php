{{--
    The instructions a study session starts from (App\Livewire\Workspaces\Instructions):
    about the student, and for this course.
--}}
<section aria-labelledby="instructions-heading" class="overview-card space-y-4">
    <div class="space-y-1">
        <h2 id="instructions-heading" class="font-semibold">Instructions for the assistant</h2>
        <p class="text-sm text-fg-muted">Written once, sent at the start of every study session, so you don't explain again.</p>
    </div>

    <div role="status" aria-live="polite">
        @if ($notice)
            <x-alert tone="success" :live="false">{{ $notice }}</x-alert>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
    @foreach ([['me', 'About you', 'Every course', $me, 'Your year, what you know already, how you like to learn.'], ['workspace', 'This course', null, $course, 'How to teach this subject, what to focus on, how to quiz you.']] as [$which, $heading, $aside, $value, $empty])
        <div class="min-w-0 space-y-1.5">
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm font-semibold">{{ $heading }} @if ($aside)<span class="font-normal text-fg-muted">· {{ $aside }}</span>@endif</h3>
                <x-button variant="ghost" icon="pencil" wire:click="edit('{{ $which }}')" aria-label="Edit: {{ $heading }}">Edit</x-button>
            </div>
            @if ($value !== '')
                <p class="line-clamp-4 text-sm break-words whitespace-pre-line">{{ $value }}</p>
            @else
                <p class="text-sm text-fg-muted">Not written yet. {{ $empty }}</p>
            @endif
        </div>
    @endforeach
    </div>
    <p class="text-sm text-fg-muted">A module can have its own too: open its menu in Modules.</p>

    <dialog id="instructions-dialog" class="modal" aria-labelledby="instructions-dialog-title"
        wire:ignore.self
        x-data
        x-on:instructions-dialog-open.window="$el.open || $el.showModal()"
        x-on:instructions-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.editing && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($editing)
            <form wire:submit="save" novalidate class="modal-panel" wire:key="instructions-{{ $editing }}">
                <div class="modal-head">
                    <h2 id="instructions-dialog-title" class="min-w-0 flex-1 text-lg font-semibold">{{ $editing === 'me' ? 'About you' : 'Instructions for this course' }}</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>
                <div class="space-y-4 px-5 pt-2">
                    <div class="field">
                        <label for="instructions-text" class="field-label">{{ $editing === 'me' ? 'What should the assistant know about you, in every course?' : 'How should the assistant work with you in this course?' }}</label>
                        <textarea id="instructions-text" class="input" rows="7" maxlength="2000" wire:model="text" autofocus aria-describedby="instructions-text-hint"></textarea>
                        <p id="instructions-text-hint" class="field-hint">
                            {{ $editing === 'me'
                                ? 'Like “I\'m in my second year of nursing. Explain with everyday examples, one step at a time.”'
                                : 'Like “Go slide by slide. After each section, ask me two questions before moving on.”' }}
                            Up to 2,000 characters.
                        </p>
                        @error('text') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="modal-actions">
                    <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                    <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save</x-button>
                </div>
            </form>
        @endif
    </dialog>
</section>
