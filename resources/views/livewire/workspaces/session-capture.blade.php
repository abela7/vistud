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
                        <p class="text-fg-muted">Untick what you don't want to keep, and change a topic or the words where you need to. Statuses change only if you tick them.</p>
                        @foreach ($groups as $kind => $heading)
                            @php $inGroup = array_filter($items, fn ($item) => $item['kind'] === $kind); @endphp
                            @if ($inGroup !== [])
                                <section class="space-y-2" aria-labelledby="capture-{{ $kind }}">
                                    <h3 id="capture-{{ $kind }}" class="text-sm font-semibold">{{ $heading }} <span class="font-normal text-fg-muted">({{ count($inGroup) }})</span></h3>
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
                                                    <input type="checkbox" class="size-4 shrink-0" wire:model.live="items.{{ $i }}.include" @disabled($item['saved'])>
                                                    <span class="font-medium">{{ $headline }}<span class="sr-only">: {{ $what }}</span></span>
                                                    @if ($item['saved'])
                                                        <span class="status-chip">Saved before</span>
                                                    @elseif ($kind === 'attempt')
                                                        <span @class(['status-chip', $resultTones[$item['result']]])>{{ $results[$item['result']] }}</span>
                                                    @endif
                                                </label>

                                                <div class="capture-body">
                                                    @if (in_array($kind, ['finding', 'question', 'summary'], true))
                                                        <textarea class="input text-sm" rows="{{ $kind === 'summary' ? 3 : 2 }}" maxlength="{{ ['finding' => 500, 'question' => 1000, 'summary' => 2000][$kind] }}" wire:model="items.{{ $i }}.text" aria-label="{{ $kindWords[$kind] }} text" @disabled($item['saved'])></textarea>
                                                    @elseif ($kind === 'flashcard')
                                                        <div class="grid gap-2 sm:grid-cols-2">
                                                            <input type="text" class="input text-sm" maxlength="500" wire:model="items.{{ $i }}.front" aria-label="Front of the flashcard" @disabled($item['saved'])>
                                                            <input type="text" class="input text-sm" maxlength="1000" wire:model="items.{{ $i }}.back" aria-label="Back of the flashcard" @disabled($item['saved'])>
                                                        </div>
                                                    @elseif ($kind === 'attempt')
                                                        <p class="text-sm"><span class="font-medium">{{ $item['asked'] }}</span>@if ($item['answer'] !== '') <span class="text-fg-muted">· You answered: {{ $item['answer'] }}</span>@endif</p>
                                                    @elseif ($kind === 'checkpoint')
                                                        <p class="text-sm">{{ $item['text'] }}</p>
                                                    @elseif ($kind === 'status' && $item['why'] !== '')
                                                        <p class="text-sm text-fg-muted">{{ $item['why'] }}</p>
                                                    @endif

                                                    @if (! in_array($kind, ['summary', 'checkpoint'], true) && ! $item['saved'])
                                                        <div class="flex flex-wrap items-center gap-2 text-sm">
                                                            <label for="capture-topic-{{ $i }}" class="text-fg-muted">Topic</label>
                                                            <select id="capture-topic-{{ $i }}" class="input capture-topic" wire:model="items.{{ $i }}.topic_id">
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
                        <x-checkbox name="note" label="Also add what you keep to the session note" wire:model="note" />
                    @endif
                </div>

                <div class="modal-actions">
                    @if ($step === 'paste')
                        <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                        <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Reading…">Find the marks</x-button>
                    @else
                        <x-button icon="arrow-left" wire:click="back">Paste again</x-button>
                        <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…" :disabled="$ticked === 0">{{ $ticked === 0 ? 'Nothing ticked' : 'Save '.$ticked }}</x-button>
                    @endif
                </div>
            </form>
        @endif
    </dialog>
</div>
