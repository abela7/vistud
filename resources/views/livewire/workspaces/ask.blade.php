{{--
    Ask (App\Livewire\Workspaces\Ask): the quick helper, in a sheet that the top bar's Ask button opens (the button is in
    layouts/app.blade.php). A short talk about the course that is not kept; a line at the bottom for when it is teaching that
    is wanted.
--}}
<div class="ask">
    <div role="status" aria-live="polite" class="empty:hidden"><x-toast :message="$notice" :tone="$noticeTone" /></div>

    <dialog id="ask-sheet" class="modal" aria-labelledby="ask-sheet-title" wire:ignore.self x-data
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel ask-panel">
            <div class="modal-head">
                <h2 id="ask-sheet-title" class="min-w-0 flex-1 text-lg font-semibold">Ask</h2>
                @if ($talk !== [])
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="clear">Start over</button>
                @endif
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()"><x-icon name="x" /></button>
            </div>

            <div class="ask-log px-5" role="log" aria-label="Talk with the helper" aria-live="polite" tabindex="0">
                @if ($talk === [])
                    <p class="text-fg-muted">Ask about your course: "What did I find hard in Module 3?", "Which topics are not started?". It looks at your topics, questions, notes and files, and changes nothing.</p>
                @endif
                @foreach ($talk as $i => $line)
                    <div @class(['ask-line', 'is-you' => $line['from'] === 'you', 'is-problem' => $line['from'] === 'problem']) wire:key="ask-{{ $i }}">
                        <span class="sr-only">{{ ['you' => 'You', 'helper' => 'Answer', 'problem' => 'Problem'][$line['from']] }}: </span>
                        <p class="whitespace-pre-line break-words">{{ $line['text'] }}</p>
                    </div>
                @endforeach
                <p class="ask-line text-fg-muted" wire:loading wire:target="send" role="status"><span class="ai-dots" aria-hidden="true"><span></span><span></span><span></span></span> Looking…</p>
            </div>

            <form wire:submit="send" novalidate class="ask-form px-5">
                <div class="min-w-0 flex-1">
                    <label for="ask-text" class="sr-only">Your question</label>
                    <input id="ask-text" class="input" type="text" wire:model="text" maxlength="{{ \App\Livewire\Workspaces\Ask::MAX_ASK }}" autocomplete="off" placeholder="Ask about your course…" wire:loading.attr="disabled" wire:target="send">
                    @error('text') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <x-button type="submit" variant="primary" icon="arrow-right" wire:loading.attr="aria-busy" wire:target="send">Ask</x-button>
            </form>

            <p class="ask-foot px-5 text-sm text-fg-muted">Need teaching? <button type="button" class="inline-link" wire:click="teach">Start a session</button>.</p>
        </div>
    </dialog>
</div>
