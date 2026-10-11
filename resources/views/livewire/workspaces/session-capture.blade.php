{{--
    Save from the chat (App\Livewire\Workspaces\SessionCapture): paste the
    tutor's replies, review what it marked, keep what you want.
--}}
@php
    use Illuminate\Support\Str;

    $groups = [
        'summary' => 'The tutor\'s summary',
        'finding' => 'Key points',
        'question' => 'Questions',
        'flashcard' => 'Flashcards',
        'attempt' => 'Your answers',
        'checkpoint' => 'Where the session stands',
        'status' => 'Statuses: you decide',
    ];
    $results = ['correct' => 'Right', 'partial' => 'Partly right', 'incorrect' => 'Not yet', 'unjudged' => 'Not marked'];
    $resultTones = ['correct' => 'status-understood', 'partial' => 'status-covered', 'incorrect' => 'status-confused', 'unjudged' => ''];
    $kindWords = ['summary' => 'Summary', 'finding' => 'Key point', 'question' => 'Question', 'flashcard' => 'Flashcard', 'attempt' => 'Answer', 'checkpoint' => 'Where it stands', 'status' => 'Status'];
@endphp
<div>
    <div role="status" aria-live="polite" class="space-y-2">
        <x-toast :message="$notice" />
        @if ($problems !== [])
            <x-alert tone="warning" :live="false" title="Some things weren't saved">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach ($problems as $problem)
                        <li>{{ $problem }}</li>
                    @endforeach
                </ul>
            </x-alert>
        @endif
    </div>

    <dialog id="capture-dialog" class="modal" aria-labelledby="capture-dialog-title"
        wire:ignore.self
        x-data
        x-on:capture-dialog-open.window="$el.open || $el.showModal()"
        x-on:capture-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.step && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($step)
            <form wire:submit="save" novalidate class="modal-panel modal-panel-wide" wire:key="capture-{{ $step }}">
                <div class="modal-head">
                    <h2 id="capture-dialog-title" class="min-w-0 flex-1 text-lg font-semibold">Save from the chat</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>

                <div class="space-y-4 px-5 pt-2">
                    @if ($step === 'paste')
                        <p class="text-fg-muted">In your AI chat, copy the tutor's replies, or the whole chat, and paste them here. ViStud finds what the tutor marked: key points, questions, flashcards, your answers and its summary. You choose what to keep.</p>
                        <div class="field">
                            <label for="capture-text" class="field-label">The tutor's replies</label>
                            <textarea id="capture-text" class="input capture-paste" rows="10" wire:model="text" autofocus aria-describedby="capture-text-hint"></textarea>
                            <p id="capture-text-hint" class="field-hint">Paste as many replies as you like. Anything already saved from this session is recognised.</p>
                            @error('text') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @else
                        @php
                            $newItems = array_filter($items, fn ($item) => ! ($item['saved'] ?? false));
                            $savedItems = array_filter($items, fn ($item) => ($item['saved'] ?? false));
                        @endphp

                        @if ($newItems === [] && $savedItems !== [])
                            <div class="rounded-xl border border-border/80 bg-surface-sunken/40 p-4 text-center space-y-1">
                                <p class="text-sm font-medium text-fg">Everything from this chat is already saved</p>
                                <p class="text-xs text-fg-muted">All marked progress and quiz answers from earlier in this session have already been saved to your course.</p>
                            </div>
                        @else
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <p class="text-sm text-fg-muted">Untick what you don't want to keep, or use quick filters:</p>
                                <div class="flex items-center gap-1.5 shrink-0">
                                    <button type="button" class="btn btn-secondary btn-xs text-xs" wire:click="setEssentialsOnly" title="Keep only summary and where you stopped">Essentials only</button>
                                    <button type="button" class="btn btn-secondary btn-xs text-xs" wire:click="toggleAll(false)">Untick all</button>
                                    <button type="button" class="btn btn-secondary btn-xs text-xs" wire:click="toggleAll(true)">Tick all</button>
                                </div>
                            </div>
                        @endif

                        @foreach ($groups as $kind => $heading)
                            @php
                                $inGroup = array_filter($items, fn ($item) => $item['kind'] === $kind && ! ($item['saved'] ?? false));
                            @endphp
                            @if ($inGroup !== [])
                                <section class="space-y-2" aria-labelledby="capture-{{ $kind }}">
                                    <h3 id="capture-{{ $kind }}" class="text-sm font-semibold flex items-center justify-between">
                                        <span>{{ $heading }}</span>
                                        <span class="font-normal text-fg-muted text-xs">({{ count($inGroup) }})</span>
                                    </h3>
                                    <ul class="module-card divide-y divide-divider" role="list">
                                        @foreach ($inGroup as $i => $item)
                                            @php
                                                $headline = match ($kind) {
                                                    'status' => 'Set '.$item['topic_name'].' to '.$item['proposed'],
                                                    'attempt' => 'Answer · '.$results[$item['result']],
                                                    default => $kindWords[$kind],
                                                };
                                                $what = Str::limit((string) ($item['text'] ?? $item['front'] ?? $item['asked'] ?? $item['why'] ?? ''), 60);
                                            @endphp
                                            <li class="capture-item" wire:key="capture-item-{{ $i }}">
                                                <label class="capture-keep">
                                                    <input type="checkbox" class="size-4 shrink-0 rounded border-border text-accent focus:ring-accent" wire:model.live="items.{{ $i }}.include" @disabled($item['saved'])>
                                                    <span class="font-medium text-fg">{{ $headline }}<span class="sr-only">: {{ $what }}</span></span>
                                                    @if ($item['saved'])
                                                        <span class="status-chip">Saved before</span>
                                                    @elseif ($kind === 'attempt')
                                                        <span @class(['status-chip', $resultTones[$item['result']]])>{{ $results[$item['result']] }}</span>
                                                    @endif
                                                </label>

                                                <div class="capture-body">
                                                    @if (in_array($kind, ['finding', 'question', 'summary'], true))
                                                        <textarea class="input text-sm rounded-lg" rows="{{ $kind === 'summary' ? 3 : 2 }}" maxlength="{{ ['finding' => 500, 'question' => 1000, 'summary' => 2000][$kind] }}" wire:model="items.{{ $i }}.text" aria-label="{{ $kindWords[$kind] }} text" @disabled($item['saved'])></textarea>
                                                    @elseif ($kind === 'flashcard')
                                                        <div class="grid gap-2 sm:grid-cols-2">
                                                            <input type="text" class="input text-sm rounded-lg" placeholder="Front (question)" maxlength="500" wire:model="items.{{ $i }}.front" aria-label="Front of the flashcard" @disabled($item['saved'])>
                                                            <input type="text" class="input text-sm rounded-lg" placeholder="Back (answer)" maxlength="1000" wire:model="items.{{ $i }}.back" aria-label="Back of the flashcard" @disabled($item['saved'])>
                                                        </div>
                                                    @elseif ($kind === 'attempt')
                                                        <div class="rounded-lg border border-border/60 bg-surface-sunken/40 p-3 space-y-2">
                                                            <div>
                                                                <p class="text-sm font-medium text-fg">{{ $item['asked'] }}</p>
                                                                @if (($item['answer'] ?? '') !== '')
                                                                    <p class="text-xs text-fg-muted mt-1"><span class="font-medium text-fg">Your answer:</span> {{ $item['answer'] }}</p>
                                                                @endif
                                                            </div>
                                                            @if (($item['right'] ?? '') !== '' || ($item['fix'] ?? '') !== '')
                                                                <div class="space-y-1 pt-1.5 border-t border-divider/60 text-xs">
                                                                    @if (($item['right'] ?? '') !== '')
                                                                        <p class="text-fg-muted"><span class="font-medium text-fg">Right:</span> {{ $item['right'] }}</p>
                                                                    @endif
                                                                    @if (($item['fix'] ?? '') !== '')
                                                                        <p class="text-fg-muted"><span class="font-medium text-fg">To fix:</span> {{ $item['fix'] }}</p>
                                                                    @endif
                                                                </div>
                                                            @endif
                                                        </div>
                                                    @elseif ($kind === 'checkpoint')
                                                        <div class="capture-checkpoint-card">
                                                            <x-icon name="bookmark" class="size-4 text-accent shrink-0 mt-0.5" />
                                                            <div class="min-w-0 flex-1 space-y-0.5">
                                                                <p class="text-sm font-medium text-fg">{{ $item['text'] }}</p>
                                                                <p class="text-xs text-fg-muted">Saves where you are so the tutor can pick up right here next time.</p>
                                                            </div>
                                                        </div>
                                                    @elseif ($kind === 'status')
                                                        <div class="capture-status-card">
                                                            <div class="flex items-center gap-2">
                                                                <span class="text-sm font-medium text-fg">{{ $item['topic_name'] }}</span>
                                                                <x-icon name="arrow-right" class="size-3 text-fg-muted shrink-0" />
                                                                <span class="status-chip">{{ ucfirst($item['proposed']) }}</span>
                                                            </div>
                                                            @if (($item['why'] ?? '') !== '')
                                                                <p class="text-xs text-fg-muted mt-0.5">{{ $item['why'] }}</p>
                                                            @endif
                                                            <p class="text-2xs text-fg-muted mt-0.5">Topic mastery will only update if this box is checked.</p>
                                                        </div>
                                                    @endif

                                                    @if (! in_array($kind, ['summary', 'checkpoint'], true) && ! $item['saved'])
                                                        <div class="flex flex-wrap items-center gap-2 pt-1 text-xs text-fg-muted">
                                                            <x-icon name="tag" class="size-3.5 shrink-0" />
                                                            <label for="capture-topic-{{ $i }}" class="font-medium">Topic</label>
                                                            <select id="capture-topic-{{ $i }}" class="input capture-topic text-xs py-1 rounded-md" wire:model="items.{{ $i }}.topic_id">
                                                                @foreach ($topics as $topic)
                                                                    <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                                                                @endforeach
                                                                @if ($item['topic_id'] === 'new' || ! collect($topics)->contains('name', $item['topic_name']))
                                                                    <option value="new">New topic: {{ $item['topic_name'] }}</option>
                                                                @endif
                                                            </select>
                                                        </div>
                                                    @endif
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>
                            @endif
                        @endforeach

                        @if ($savedItems !== [])
                            <details class="capture-saved-accordion" @if($newItems === []) open @endif>
                                <summary class="capture-saved-summary">
                                    <span class="flex items-center gap-2">
                                        <x-icon name="history" class="size-4 text-fg-muted" />
                                        <span>Saved earlier in this session ({{ count($savedItems) }})</span>
                                    </span>
                                    <span class="status-chip">Saved before</span>
                                </summary>
                                <ul class="capture-saved-list" role="list">
                                    @foreach ($savedItems as $i => $item)
                                        @php
                                            $headline = match ($item['kind']) {
                                                'status' => 'Status · '.$item['topic_name'].' ('.$item['proposed'].')',
                                                'attempt' => 'Answer · '.($results[$item['result']] ?? 'Answer'),
                                                default => $kindWords[$item['kind']] ?? ucfirst($item['kind']),
                                            };
                                            $what = Str::limit((string) ($item['text'] ?? $item['front'] ?? $item['asked'] ?? $item['why'] ?? ''), 80);
                                        @endphp
                                        <li class="capture-saved-item" wire:key="capture-saved-{{ $i }}">
                                            <div class="min-w-0 flex-1">
                                                <span class="font-medium text-fg">{{ $headline }}</span>
                                                @if ($what !== '')
                                                    <p class="truncate text-xs text-fg-muted mt-0.5">{{ $what }}</p>
                                                @endif
                                            </div>
                                            <span class="status-chip shrink-0">Saved before</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif

                        <div class="capture-note-card">
                            <label class="flex items-start gap-3 cursor-pointer select-none w-full">
                                <input type="checkbox" name="note" class="size-4 shrink-0 mt-0.5 rounded border-border text-accent focus:ring-accent" wire:model="note">
                                <div class="space-y-0.5">
                                    <span class="text-sm font-medium text-fg block">Also add what you keep to the session note</span>
                                    <span class="text-xs text-fg-muted block">Summarizes your saved points and quiz results into this module's study note for quick reference.</span>
                                </div>
                            </label>
                        </div>
                    @endif
                </div>

                <div class="modal-actions">
                    @if ($step === 'paste')
                        <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                        <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Reading…">Find what to save</x-button>
                    @else
                        <x-button icon="arrow-left" wire:click="back">Paste again</x-button>
                        <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…" :disabled="$ticked === 0">{{ $ticked === 0 ? 'Nothing ticked' : 'Save '.$ticked }}</x-button>
                    @endif
                </div>
            </form>
        @endif
    </dialog>
</div>
