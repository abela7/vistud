{{--
    A workspace's Flashcards section (App\Livewire\Workspaces\Deck): what's
    due, the cards by topic, and the ways to add them.
--}}
@php
    use Carbon\CarbonImmutable;

    $topicNames = collect($topics)->pluck('name', 'id');
    $chosen = $topic !== '' && $topic !== 'none' ? $topic : null;
    $review = fn (array $extra = []) => route('workspaces.flashcards.review', [$workspaceId, ...array_filter(['topic' => $topic ?: null] + $extra)]);
    $plural = fn (int $n, string $one, string $many) => $n === 1 ? "1 {$one}" : "{$n} {$many}";
    $nextWords = null;
    if ($counts['next_on'] !== null) {
        $days = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($counts['next_on']));
        $when = match (true) {
            $days === 1 => 'tomorrow',
            $days < 7 => 'in '.$days.' days',
            default => 'on '.CarbonImmutable::parse($counts['next_on'])->format('j M'),
        };
        $nextWords = 'Next: '.$plural($counts['next_count'], 'card', 'cards').' '.$when.'.';
    }
    $groups = [...array_map(fn ($t) => [$t->id, $t->name], $topics), ['', 'No topic']];
@endphp
<div class="space-y-6">
    <livewire:workspaces.card-maker :workspace-id="$workspaceId" />

    @if ($all['total'] === 0)
        <section class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border-strong px-6 py-12 text-center">
            <span class="ws-chip size-14"><x-icon name="gallery-vertical-end" class="size-6" /></span>
            <h2 class="text-lg font-semibold">No flashcards yet</h2>
            <p class="max-w-md text-fg-muted">A card asks one thing on the front, like “What does a LEFT JOIN keep?”, and answers it on the back. You review each one a day later, then a few days later, then weeks later: the ones you know come back less, the ones you miss come back sooner.</p>
            <p class="max-w-md text-fg-muted">Write your own, have an AI make them from your notes, or save the ones the tutor makes in a study session.</p>
            <div class="flex flex-wrap justify-center gap-2">
                <x-button icon="plus" x-data x-on:click="Livewire.dispatch('flashcard-new')">Write a card</x-button>
                <x-button variant="primary" icon="wand-sparkles" x-data x-on:click="Livewire.dispatch('card-maker-open')">Make cards with an AI</x-button>
            </div>
        </section>
    @else
        <section class="deck-summary" aria-labelledby="deck-due-heading">
            <div class="flex min-w-0 items-center gap-4">
                <span @class(['deck-due-number', 'is-clear' => $counts['due'] === 0]) aria-hidden="true">
                    @if ($counts['due'] === 0)
                        <x-icon name="check" class="size-7" />
                    @else
                        {{ $counts['due'] }}
                    @endif
                </span>
                <div class="min-w-0">
                    <h2 id="deck-due-heading" class="text-lg font-semibold">
                        {{ $counts['due'] === 0 ? 'All caught up' : $plural($counts['due'], 'card', 'cards').' to review today' }}{{ $chosen ? ' in '.$topicNames[$chosen] : '' }}
                    </h2>
                    <p class="text-sm text-fg-muted">
                        {{ implode(' ', array_filter([
                            $plural($counts['total'], 'card', 'cards').($counts['new'] > 0 ? ', '.$counts['new'].' new.' : '.'),
                            $counts['due'] === 0 ? $nextWords : null,
                        ])) }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($counts['due'] > 0)
                    <a href="{{ $review() }}" class="btn btn-primary btn-lg"><x-icon name="play" class="size-4" />Review {{ $counts['due'] > \App\Study\Flashcards::ROUND ? \App\Study\Flashcards::ROUND.' now' : 'now' }}</a>
                @elseif ($counts['total'] > 0)
                    <a href="{{ $review(['early' => 1]) }}" class="btn btn-secondary"><x-icon name="rotate-ccw" class="size-4" />Practise anyway</a>
                @endif
            </div>
        </section>

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="field min-w-0">
                <label for="deck-topic" class="field-label">Show</label>
                <select id="deck-topic" class="input deck-filter" wire:model.live="topic">
                    <option value="">All topics ({{ $all['total'] }})</option>
                    @foreach ($topics as $t)
                        @if (isset($all['topics'][$t->id]))
                            <option value="{{ $t->id }}">{{ $t->name }} ({{ $all['topics'][$t->id]['total'] }})</option>
                        @endif
                    @endforeach
                    @if (isset($all['topics']['']))
                        <option value="none">No topic ({{ $all['topics']['']['total'] }})</option>
                    @endif
                </select>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-button icon="plus" x-data x-on:click="Livewire.dispatch('flashcard-new', { topicId: {{ \Illuminate\Support\Js::from($chosen) }} })">New card</x-button>
                <x-button icon="wand-sparkles" x-data x-on:click="Livewire.dispatch('card-maker-open', { topicId: {{ \Illuminate\Support\Js::from($chosen) }} })">Make cards with an AI</x-button>
            </div>
        </div>

        @if ($cards === [])
            <p class="rounded-xl border border-dashed border-border-strong px-6 py-8 text-center text-fg-muted">No cards {{ $chosen ? 'on '.$topicNames[$chosen] : 'without a topic' }} yet.</p>
        @endif

        @foreach ($groups as [$groupId, $groupName])
            @if (isset($byTopic[$groupId]))
                @php
                    $groupDue = count(array_filter($byTopic[$groupId], fn ($card) => $card->due($today)));
                @endphp
                <section class="space-y-2" aria-labelledby="deck-group-{{ $groupId ?: 'none' }}" wire:key="deck-group-{{ $groupId ?: 'none' }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h3 id="deck-group-{{ $groupId ?: 'none' }}" class="font-semibold">
                            {{ $groupName }} <span class="text-sm font-normal text-fg-muted">· {{ $plural(count($byTopic[$groupId]), 'card', 'cards') }}{{ $groupDue > 0 ? ' · '.$groupDue.' due' : '' }}</span>
                        </h3>
                        @if ($groupDue > 0 && $topic === '')
                            <a href="{{ route('workspaces.flashcards.review', [$workspaceId, 'topic' => $groupId ?: 'none']) }}" class="item-link text-sm">Review {{ $groupName === 'No topic' ? 'these' : $groupName }}</a>
                        @endif
                    </div>
                    <ul class="module-card divide-y divide-divider" role="list">
                        @foreach ($byTopic[$groupId] as $card)
                            <li class="card-row" wire:key="card-{{ $card->id }}">
                                <div class="min-w-0 flex-1 space-y-1">
                                    <p class="font-medium break-words">{{ $card->front }}</p>
                                    <p class="text-sm break-words text-fg-muted">{{ $card->back }}</p>
                                    <p class="flex flex-wrap gap-2 pt-1 text-sm">
                                        <span @class(['status-chip', 'due-now' => $card->due($today)])><x-icon :name="$card->due($today) ? 'circle-dot' : 'calendar-clock'" class="size-3.5" />{{ $card->dueWords($today) }}</span>
                                        @if ($card->author === 'ai')
                                            <span class="status-chip"><x-icon name="sparkles" class="size-3.5" />{{ $card->sessionId ? 'From a study session' : 'Made with an AI' }}</span>
                                        @endif
                                    </p>
                                </div>
                                @include('livewire.workspaces.partials.row-menu', ['id' => 'card-'.$card->id, 'label' => \Illuminate\Support\Str::limit($card->front, 60), 'items' => [
                                    ['Edit', 'pencil', "editCard('{$card->id}')", false],
                                    ['Delete', 'trash-2', "deleteCard('{$card->id}')", false],
                                ]])
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endforeach
    @endif

    <livewire:workspaces.flashcard-editor :workspace-id="$workspaceId" />
</div>
