{{--
    The ✦ menu's sheet (App\Livewire\Workspaces\AiAssist; docs/specs/vistud-2-blueprint.md §3.6.5): what the helper or the
    reader made of the thing the student pointed at, shown as a proposal to keep or discard, never applied silently.
--}}
<div>
    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" />
    </div>

    <dialog id="ai-sheet" class="modal" aria-labelledby="ai-sheet-title"
        wire:ignore.self
        x-data
        x-on:ai-sheet-open.window="$el.open || $el.showModal()"
        x-on:ai-sheet-close.window="$el.open && $el.close()"
        x-on:close="$wire.step && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($step)
            @php $kind = $proposal['kind'] ?? null; @endphp
            <div class="modal-panel" wire:key="ai-{{ $round }}-{{ $step }}">
                <div class="modal-head">
                    <h2 id="ai-sheet-title" class="min-w-0 flex-1 text-lg font-semibold break-words">{{ $title }}</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()"><x-icon name="x" /></button>
                </div>

                @if ($step === 'choose')
                    <form wire:submit="chosen" novalidate>
                        <div class="space-y-4 px-5 pt-2">
                            <fieldset class="field">
                                <legend class="field-label">Make cards from</legend>
                                <div class="segmented" role="group" aria-label="Make cards from">
                                    @foreach (['topic' => 'A topic', 'note' => 'A note', 'file' => 'A file'] as $value => $word)
                                        <button type="button" @class(['segmented-option', 'is-current' => $sourceType === $value]) wire:click="$set('sourceType', '{{ $value }}')" aria-pressed="{{ $sourceType === $value ? 'true' : 'false' }}">{{ $word }}</button>
                                    @endforeach
                                </div>
                            </fieldset>
                            <div class="field">
                                <label for="ai-source" class="field-label">{{ ['topic' => 'Topic', 'note' => 'Note', 'file' => 'File'][$sourceType] ?? 'Topic' }}</label>
                                <select id="ai-source" class="input" wire:model="sourceId" wire:key="ai-source-{{ $sourceType }}">
                                    <option value="">Choose…</option>
                                    @if ($sourceType === 'topic')
                                        @foreach ($topics as $topic)
                                            <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                                        @endforeach
                                    @elseif ($sourceType === 'note')
                                        @foreach ($notes as $note)
                                            <option value="{{ $note->id }}">{{ $note->displayTitle() }}</option>
                                        @endforeach
                                    @else
                                        @foreach ($files as $file)
                                            <option value="{{ $file->id }}">{{ $file->fileName() }}</option>
                                        @endforeach
                                    @endif
                                </select>
                                @error('sourceId') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="field">
                                <label for="ai-count" class="field-label">How many</label>
                                <select id="ai-count" class="input" wire:model="count">
                                    @foreach ($counts as $n)
                                        <option value="{{ $n }}">{{ $n }} cards</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="modal-actions">
                            <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                            <x-button type="submit" variant="primary" icon="sparkles" wire:loading.attr="aria-busy" wire:target="chosen" busy-label="Starting…">Make cards</x-button>
                        </div>
                    </form>
                @elseif ($step === 'working')
                    <div class="ai-working px-5 pt-3" role="status" wire:init="run">
                        <span class="ai-dots" aria-hidden="true"><span></span><span></span><span></span></span>
                        <p>Working on it… this takes a few seconds.</p>
                    </div>
                    <div class="modal-actions">
                        <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                    </div>
                @elseif ($step === 'failed')
                    <div class="space-y-3 px-5 pt-2">
                        <p role="alert">{{ $error }}</p>
                        <p class="text-sm text-fg-muted">If the AI is not set up yet, <a href="{{ route('settings', ['part' => 'ai']) }}" class="inline-link">open the AI settings</a>.</p>
                    </div>
                    <div class="modal-actions">
                        <x-button x-on:click="$el.closest('dialog').close()">Close</x-button>
                        <x-button variant="primary" icon="rotate-ccw" wire:click="$set('step', 'working')">Try again</x-button>
                    </div>
                @else
                    <div class="space-y-4 px-5 pt-2">
                        @if ($kind === 'card')
                            <div class="ai-compare">
                                <section aria-labelledby="ai-before"><h3 id="ai-before" class="field-label">Before</h3>
                                    <p class="font-medium break-words">{{ $proposal['before']['front'] }}</p><p class="text-sm break-words text-fg-muted">{{ $proposal['before']['back'] }}</p></section>
                                <section aria-labelledby="ai-after" class="ai-after"><h3 id="ai-after" class="field-label">After</h3>
                                    <p class="font-medium break-words">{{ $proposal['cards'][0]['front'] }}</p><p class="text-sm break-words">{{ $proposal['cards'][0]['back'] }}</p></section>
                            </div>
                        @elseif ($kind === 'cards-add')
                            <ul class="grid gap-2" role="list" aria-label="New cards">
                                @foreach ($proposal['cards'] as $new)
                                    <li class="ai-card"><p class="font-medium break-words">{{ $new['front'] }}</p><p class="text-sm break-words text-fg-muted">{{ $new['back'] }}</p></li>
                                @endforeach
                            </ul>
                        @elseif ($kind === 'question')
                            <div class="ai-compare">
                                <section aria-labelledby="ai-before"><h3 id="ai-before" class="field-label">Before</h3><p class="break-words">{{ $proposal['before'] }}</p></section>
                                <section aria-labelledby="ai-after" class="ai-after"><h3 id="ai-after" class="field-label">{{ $proposal['verb'] === 'split' ? 'Two questions' : 'After' }}</h3>
                                    @foreach ($proposal['questions'] as $line)
                                        <p class="break-words">{{ $line }}</p>
                                    @endforeach
                                </section>
                            </div>
                        @elseif ($kind === 'answer')
                            <div class="ai-after">
                                <p class="break-words">{{ $proposal['answer'] }}</p>
                                <p class="pt-1 text-sm text-fg-muted">From your notes{{ $proposal['from'] !== [] ? ': '.implode(', ', $proposal['from']) : '' }}.</p>
                            </div>
                        @elseif ($kind === 'text')
                            <div class="ai-after"><p class="break-words whitespace-pre-line">{{ $proposal['text'] }}</p></div>
                        @elseif ($kind === 'words')
                            <p class="break-words whitespace-pre-line">{{ $proposal['text'] }}</p>
                        @elseif ($kind === 'digest')
                            <div class="space-y-3">
                                <p class="break-words">{{ $proposal['summary'] }}</p>
                                @if ($proposal['topics'] !== [])
                                    <p class="text-sm"><span class="field-label">Topics</span> {{ implode(' · ', $proposal['topics']) }}</p>
                                @endif
                                @if ($proposal['outline'] !== [])
                                    <ul class="grid gap-1 text-sm text-fg-muted" role="list" aria-label="Outline">
                                        @foreach ($proposal['outline'] as $item)
                                            <li>{{ $item['heading'] }} <span class="tabular-nums">· p. {{ $item['page'] }}</span></li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @elseif ($kind === 'note')
                            <p>Made the note <strong class="break-words">{{ $proposal['title'] }}</strong>. It is in the same place as the file, and says the AI wrote it.</p>
                        @elseif ($kind === 'topics')
                            @if ($proposal['found'] > 0)
                                <p>{{ $proposal['found'] }} new {{ $proposal['found'] === 1 ? 'topic' : 'topics' }} found: {{ implode(', ', $proposal['names']) }}. They wait on the module's Topics tab until you add them.</p>
                            @elseif ($proposal['names'] === [])
                                <p>The AI found no topics in it.</p>
                            @else
                                <p>Nothing new: the module has these topics already, or you said no to them: {{ implode(', ', $proposal['names']) }}.</p>
                            @endif
                        @elseif ($kind === 'cards')
                            <p class="text-sm text-fg-muted">Untick the ones you do not want, and change the words if you like. They are added to your deck when you press Add.</p>
                            <ul class="grid gap-3" role="list" aria-label="Cards to add">
                                @foreach ($cards as $i => $card)
                                    <li class="ai-card" wire:key="ai-card-{{ $round }}-{{ $i }}">
                                        <label class="checkbox-row"><span class="checkbox-box"><input type="checkbox" class="checkbox" wire:model.live="cards.{{ $i }}.include"><x-icon name="check" class="checkbox-mark size-3.5" stroke-width="3" /></span><span class="sr-only">Add card {{ $i + 1 }}</span></label>
                                        <div class="min-w-0 flex-1 space-y-2">
                                            <div class="field"><label for="ai-front-{{ $i }}" class="sr-only">Front of card {{ $i + 1 }}</label>
                                                <textarea id="ai-front-{{ $i }}" class="input" rows="2" maxlength="500" wire:model.blur="cards.{{ $i }}.front"></textarea></div>
                                            <div class="field"><label for="ai-back-{{ $i }}" class="sr-only">Back of card {{ $i + 1 }}</label>
                                                <textarea id="ai-back-{{ $i }}" class="input" rows="2" maxlength="1000" wire:model.blur="cards.{{ $i }}.back"></textarea></div>
                                            @if ($card['topic'] !== null)
                                                <p class="text-sm text-fg-muted">Topic: {{ $card['topic'] }}</p>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="modal-actions">
                        @if ($kind === 'note')
                            <x-button variant="ghost" icon="trash-2" wire:click="removeNote">Remove it</x-button>
                            <a href="{{ $proposal['url'] }}" class="btn btn-primary" wire:navigate><x-icon name="file-text" class="size-4" />Open it</a>
                        @elseif (in_array($kind, ['words', 'digest', 'topics'], true))
                            <x-button x-on:click="$el.closest('dialog').close()">Close</x-button>
                            @if ($kind === 'topics' && ($proposal['url'] ?? null) !== null && $proposal['found'] > 0)
                                <a href="{{ $proposal['url'] }}" class="btn btn-primary" wire:navigate>Open the topics</a>
                            @endif
                        @else
                            <x-button x-on:click="$el.closest('dialog').close()">Discard</x-button>
                            <x-button variant="primary" icon="check" wire:click="keep" wire:loading.attr="aria-busy" wire:target="keep" busy-label="Saving…" :disabled="$kind === 'cards' && $ticked === 0">
                                {{ match ($kind) {
                                    'cards' => $ticked === 1 ? 'Add 1 card' : 'Add '.$ticked.' cards',
                                    'cards-add' => 'Add '.count($proposal['cards']).' cards',
                                    'answer' => 'Keep as the answer',
                                    'text' => ($proposal['verb'] ?? '') === 'explain' ? 'Add below' : 'Replace the text',
                                    'question' => ($proposal['verb'] ?? '') === 'split' ? 'Use the two' : 'Use this',
                                    default => 'Keep',
                                } }}
                            </x-button>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </dialog>
</div>
