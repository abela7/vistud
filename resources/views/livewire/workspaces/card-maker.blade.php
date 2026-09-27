{{--
    Making flashcards with any AI (App\Livewire\Workspaces\CardMaker): copy
    a prompt into an AI, paste its reply back, keep the cards you want.
--}}
@php use Illuminate\Support\Str; @endphp
<div>
    <div role="status" aria-live="polite" class="space-y-2 empty:hidden">
        <x-toast :message="$notice" />
        @if ($problems !== [])
            <x-alert tone="warning" :live="false" title="Some cards weren't added">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($problems as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif
    </div>

    <dialog id="card-maker-dialog" class="modal" aria-labelledby="card-maker-dialog-title"
        wire:ignore.self
        x-data
        x-on:card-maker-dialog-open.window="$el.open || $el.showModal()"
        x-on:card-maker-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.step && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($step)
            <form wire:submit="save" novalidate class="modal-panel modal-panel-wide" wire:key="card-maker-{{ $step }}" x-data="{ copied: false }">
                <div class="modal-head">
                    <h2 id="card-maker-dialog-title" class="min-w-0 flex-1 text-lg font-semibold">Make cards with an AI</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>

                <div class="space-y-5 px-5 pt-2">
                    @if ($step === 'prompt')
                        <p class="text-fg-muted">Any AI can write your cards: ChatGPT, Gemini, Claude or another. Copy the prompt into it, then paste its reply below. You choose which cards to keep.</p>

                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="field">
                                <label for="card-maker-topic" class="field-label">About</label>
                                <select id="card-maker-topic" class="input" wire:model.live="topicId">
                                    <option value="">All topics</option>
                                    @foreach ($topics as $topic)
                                        <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label for="card-maker-count" class="field-label">How many</label>
                                <select id="card-maker-count" class="input" wire:model.live="count">
                                    @foreach (\App\Study\CardMaker::COUNTS as $n)
                                        <option value="{{ $n }}">{{ $n }} cards</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field">
                                <label for="card-maker-note" class="field-label">From a note</label>
                                <select id="card-maker-note" class="input" wire:model.live="noteId">
                                    <option value="">No note, key points only</option>
                                    @foreach ($notes as $note)
                                        <option value="{{ $note->id }}">{{ $note->displayTitle() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <section class="space-y-2" aria-labelledby="card-maker-step-1">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 id="card-maker-step-1" class="font-semibold"><span class="step-number" aria-hidden="true">1</span>Copy the prompt into an AI</h3>
                                <x-button icon="copy" x-on:click="
                                    const text = $refs.prompt.textContent;
                                    const done = () => { copied = true; setTimeout(() => copied = false, 2500); };
                                    const select = () => {
                                        const range = document.createRange();
                                        range.selectNodeContents($refs.prompt);
                                        const selection = window.getSelection();
                                        selection.removeAllRanges();
                                        selection.addRange(range);
                                        try { if (document.execCommand('copy')) done(); } catch (e) {}
                                    };
                                    navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText(text).then(done, select) : select();
                                "><span x-show="! copied">Copy the prompt</span><span x-show="copied" x-cloak>Copied</span></x-button>
                            </div>
                            <pre class="briefing-text card-maker-prompt" tabindex="0" aria-label="The prompt" x-ref="prompt" wire:loading.class="opacity-60" wire:target="topicId,count,noteId">{{ $prompt }}</pre>
                            <p class="sr-only" role="status" x-text="copied ? 'Copied to the clipboard.' : ''"></p>
                        </section>

                        <section class="space-y-2" aria-labelledby="card-maker-step-2">
                            <h3 id="card-maker-step-2" class="font-semibold"><span class="step-number" aria-hidden="true">2</span>Paste its reply here</h3>
                            <div class="field">
                                <label for="card-maker-text" class="sr-only">The AI's reply</label>
                                <textarea id="card-maker-text" class="input capture-paste" rows="6" wire:model="text" aria-describedby="card-maker-text-hint"></textarea>
                                <p id="card-maker-text-hint" class="field-hint">The whole reply is fine: only the cards are read. Cards you already have are recognised.</p>
                                @error('text') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        </section>
                    @else
                        <p class="text-fg-muted">{{ count($cards) === 1 ? '1 card found.' : count($cards).' cards found.' }} Untick any you don't want, and fix the words or the topic where you need to.</p>
                        <ul class="module-card divide-y divide-divider" role="list" aria-label="Cards found">
                            @foreach ($cards as $i => $card)
                                <li class="capture-item" wire:key="card-maker-card-{{ $i }}">
                                    <label class="capture-keep">
                                        <input type="checkbox" class="size-4 shrink-0" wire:model.live="cards.{{ $i }}.include" @disabled($card['known'])>
                                        <span class="font-medium">Card {{ $i + 1 }}<span class="sr-only">: {{ Str::limit($card['front'], 60) }}</span></span>
                                        @if ($card['known'])
                                            <span class="status-chip">Already a card</span>
                                        @endif
                                    </label>
                                    <div class="capture-body">
                                        <div class="grid gap-2 sm:grid-cols-2">
                                            <textarea class="input text-sm" rows="2" maxlength="{{ \App\Study\Flashcards::MAX_FRONT }}" wire:model="cards.{{ $i }}.front" aria-label="Front of card {{ $i + 1 }}" @disabled($card['known'])></textarea>
                                            <textarea class="input text-sm" rows="2" maxlength="{{ \App\Study\Flashcards::MAX_BACK }}" wire:model="cards.{{ $i }}.back" aria-label="Back of card {{ $i + 1 }}" @disabled($card['known'])></textarea>
                                        </div>
                                        @if (! $card['known'])
                                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                                <label for="card-maker-topic-{{ $i }}" class="text-fg-muted">Topic</label>
                                                <select id="card-maker-topic-{{ $i }}" class="input capture-topic" wire:model="cards.{{ $i }}.topic_id">
                                                    <option value="">No topic</option>
                                                    @foreach ($topics as $topic)
                                                        <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="modal-actions">
                    @if ($step === 'prompt')
                        <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                        <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Reading…">Read the cards</x-button>
                    @else
                        <x-button icon="arrow-left" wire:click="back">Paste again</x-button>
                        <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Adding…" :disabled="$ticked === 0">{{ $ticked === 0 ? 'Nothing ticked' : ($ticked === 1 ? 'Add 1 card' : 'Add '.$ticked.' cards') }}</x-button>
                    @endif
                </div>
            </form>
        @endif
    </dialog>
</div>
