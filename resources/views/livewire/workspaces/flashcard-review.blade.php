{{--
    A round of flashcard review (App\Livewire\Workspaces\FlashcardReview):
    one card at a time, turned over with a click or Space, answered with a
    click or 1, 2 or 3.
--}}
@php
    use Carbon\CarbonImmutable;

    $answers = [
        'incorrect' => ['Not yet', '1'],
        'partial' => ['Partly', '2'],
        'correct' => ['Got it', '3'],
    ];
    $back = route('workspaces.show', [$workspaceId, 'flashcards']).($topicId !== null ? '?topic='.($topicId === '' ? 'none' : $topicId) : '');
    $plural = fn (int $n, string $one, string $many) => $n === 1 ? "1 {$one}" : "{$n} {$many}";
    $done = $total > 0 ? min($position, $total) : 0;
    $nextWords = null;
    if ($counts !== null && $counts['next_on'] !== null) {
        $days = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($counts['next_on']));
        $nextWords = $plural($counts['next_count'], 'card', 'cards').' '.match (true) {
            $days === 1 => 'tomorrow',
            $days < 7 => 'in '.$days.' days',
            default => 'on '.CarbonImmutable::parse($counts['next_on'])->format('j M'),
        };
    }
@endphp
<div class="space-y-6">
    <div class="min-w-0">
        <p class="text-sm break-words text-fg-muted">
            <a href="{{ $back }}" class="item-link font-normal">Flashcards</a>{{ $roundTopic ? ' · '.$roundTopic : '' }}
        </p>
        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ $early ? 'Practise' : 'Review' }}</h1>
    </div>

    @if ($card)
        <div class="space-y-2">
            <div class="flex items-center justify-between gap-3 text-sm">
                <p class="font-medium" role="status">Card {{ $position + 1 }} of {{ $total }}</p>
                <p class="flex flex-wrap justify-end gap-x-3 text-fg-muted">
                    <span>{{ $tally['correct'] }} got it</span><span>{{ $tally['partial'] }} partly</span><span>{{ $tally['incorrect'] }} not yet</span>
                </p>
            </div>
            <div class="review-progress" role="progressbar" aria-label="Cards done" aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $done }}">
                <span style="width: {{ $total > 0 ? round($done / $total * 100, 2) : 0 }}%"></span>
            </div>
        </div>

        <div class="space-y-4" wire:key="review-{{ $rounds }}-{{ $position }}"
            x-data="{
                shown: false,
                busy: false,
                flip() {
                    if (this.shown) return;
                    this.shown = true;
                    this.$nextTick(() => this.$refs.back.focus({ preventScroll: true }));
                },
                answer(result) {
                    if (! this.shown || this.busy) return;
                    this.busy = true;
                    this.$wire.answer(result).finally(() => this.busy = false);
                },
                key(event) {
                    if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey || document.querySelector('dialog[open]')) return;
                    if (event.target.closest('input, textarea, select, [contenteditable]')) return;
                    if ((event.key === ' ' || event.key === 'Enter') && ! this.shown) {
                        if (event.target.closest('button, a')) return;
                        event.preventDefault();
                        this.flip();
                    } else if (this.shown && ['1', '2', '3'].includes(event.key)) {
                        event.preventDefault();
                        this.answer(['incorrect', 'partial', 'correct'][Number(event.key) - 1]);
                    }
                },
            }"
            @if ($position > 0 || $rounds > 1) x-init="$nextTick(() => $refs.front.focus({ preventScroll: true }))" @endif
            x-on:keydown.window="key($event)">
            @if ($retry)
                <x-alert tone="info" :live="false">Another go at a card you missed. It's practice: when it comes back is already set.</x-alert>
            @elseif ($early && $position === 0)
                <x-alert tone="info" :live="false">Practising early: your answers are recorded, and when each card comes back stays as it is.</x-alert>
            @endif

            <div class="flip-card" x-bind:class="shown && 'is-flipped'">
                <div class="flip-card-inner">
                    <section class="flip-face" x-ref="front" tabindex="-1" aria-label="Question" x-effect="$el.inert = shown" x-bind:aria-hidden="shown ? 'true' : 'false'" x-on:click="flip()">
                        <div class="flip-meta">
                            <span class="min-w-0 truncate">{{ $cardTopic ?? 'No topic' }}</span>
                            @if ($card->isNew())
                                <span class="status-chip due-now">New</span>
                            @endif
                        </div>
                        <p class="flip-text">{{ $card->front }}</p>
                        <p class="flip-cue" aria-hidden="true">Tap to turn over</p>
                    </section>
                    <section class="flip-face flip-back" x-ref="back" tabindex="-1" aria-label="Answer" inert aria-hidden="true" x-effect="$el.inert = ! shown" x-bind:aria-hidden="shown ? 'false' : 'true'">
                        <div class="flip-meta">
                            <span class="min-w-0 truncate">{{ $cardTopic ?? 'No topic' }}</span>
                        </div>
                        <p class="flip-question">{{ $card->front }}</p>
                        <p class="flip-text">{{ $card->back }}</p>
                    </section>
                </div>
            </div>

            <div x-show="! shown">
                <button type="button" class="btn btn-primary btn-lg w-full" x-on:click="flip()">
                    Show the answer <kbd class="key-hint">Space</kbd>
                </button>
            </div>
            <div x-show="shown" x-cloak>
                <p id="review-how" class="mb-2 text-center text-sm font-medium">How did it go?</p>
                <div class="answer-bar" role="group" aria-labelledby="review-how">
                    @foreach ($answers as $result => [$word, $key])
                        <button type="button" class="answer-button answer-{{ $result }}" x-on:click="answer('{{ $result }}')" x-bind:aria-busy="busy ? 'true' : 'false'">
                            <span class="answer-word">{{ $word }}</span>
                            @if (isset($hints[$result]))
                                <span class="answer-hint">{{ $result === 'incorrect' ? 'Again this round' : ($hints[$result] === 'tomorrow' ? 'Back tomorrow' : 'Back in '.$hints[$result]) }}</span>
                            @endif
                            <kbd class="key-hint" aria-hidden="true">{{ $key }}</kbd>
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-fg-muted">
                <p class="flex items-center gap-1.5 max-sm:hidden"><x-icon name="keyboard" class="size-4" />Space turns the card over; 1, 2 or 3 answers.</p>
                <button type="button" class="item-link font-normal" wire:click="editCard">Edit this card</button>
            </div>
        </div>
    @elseif ($total === 0)
        <section class="review-done" aria-labelledby="review-empty-heading">
            <span class="ws-chip size-14"><x-icon :name="$counts['total'] === 0 ? 'gallery-vertical-end' : 'circle-check'" class="size-6" /></span>
            <h2 id="review-empty-heading" class="text-lg font-semibold">
                {{ $counts['total'] === 0 ? 'No cards here yet' : ($early ? 'Nothing left to practise early' : 'Nothing to review right now') }}
            </h2>
            @if ($counts['total'] > 0 && ! $early)
                <p class="text-fg-muted">{{ $nextWords ? 'Next up: '.$nextWords.'. ' : '' }}Coming back when a card is due is what makes it stick, but you can practise now: it won't change when they come back.</p>
            @elseif ($counts['total'] === 0)
                <p class="text-fg-muted">Write cards or make them with an AI in Flashcards, then review them here.</p>
            @endif
            <div class="flex flex-wrap justify-center gap-2">
                <a href="{{ $back }}" class="btn btn-secondary"><x-icon name="arrow-left" class="size-4" />Back to flashcards</a>
                @if ($counts['total'] > 0 && ! $early)
                    <x-button variant="primary" icon="rotate-ccw" wire:click="again(true)">Practise anyway</x-button>
                @endif
            </div>
        </section>
    @else
        <section class="review-done" aria-labelledby="review-done-heading" x-data x-init="$nextTick(() => $refs.heading.focus())">
            <span class="ws-chip size-14"><x-icon name="party-popper" class="size-6" /></span>
            <h2 id="review-done-heading" class="text-lg font-semibold" tabindex="-1" x-ref="heading">{{ $early ? 'Practice done' : 'Round done' }}</h2>
            <p class="text-fg-muted">You went through {{ $plural(count($firsts), 'card', 'cards') }}{{ $tally['incorrect'] > 0 ? ', and had another go at the ones you missed' : '' }}.</p>
            <ul class="review-tally" role="list">
                <li class="answer-correct"><span class="tally-number">{{ $tally['correct'] }}</span>Got it</li>
                <li class="answer-partial"><span class="tally-number">{{ $tally['partial'] }}</span>Partly</li>
                <li class="answer-incorrect"><span class="tally-number">{{ $tally['incorrect'] }}</span>Not yet</li>
            </ul>
            <p class="text-fg-muted">
                {{ $counts['due'] > 0 ? $plural($counts['due'], 'more card is', 'more cards are').' due today.' : 'All caught up.'.($nextWords ? ' Next up: '.$nextWords.'.' : '') }}
            </p>
            <div class="flex flex-wrap justify-center gap-2">
                <a href="{{ $back }}" @class(['btn', 'btn-secondary' => $counts['due'] > 0, 'btn-primary' => $counts['due'] === 0])><x-icon name="arrow-left" class="size-4" />Back to flashcards</a>
                @if ($counts['due'] > 0)
                    <x-button variant="primary" icon="play" wire:click="again">Review {{ min($counts['due'], \App\Study\Flashcards::ROUND) }} more</x-button>
                @endif
            </div>
        </section>
    @endif

    <livewire:workspaces.flashcard-editor :workspace-id="$workspaceId" />
</div>
