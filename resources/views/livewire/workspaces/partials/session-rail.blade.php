{{--
    The session's rail (App\Livewire\Workspaces\StudySession): the module's topics, or the folder's in a folder's session (tap one to make the session about it),
    the material (open it; Use gives it to the tutor), and what this session saved. Drawn twice, beside the chat and in a
    sheet on a phone, so $suffix keeps their ids apart.
--}}
@php
    $saved = \App\Livewire\Workspaces\TutorChat::savedWords($activity['saved']);
    $quizzed = $activity['quizzes'] > 0 ? Str::plural('quiz or test', $activity['quizzes'], prependCount: true) : null;
    $items = array_slice([...array_merge([], ...array_column($groups, 'items')), ...$alsoUsed], 0, 6);
    $tutoring = $tutoring ?? \App\Study\Tutoring::normalised($session->tutoring);
    $tutoringChoices = $tutoringChoices ?? \App\Study\Tutoring::CHOICES;
@endphp
<section class="rail-section" aria-labelledby="rail-topics-{{ $suffix }}" x-data="{ openSection: true }">
    <button type="button" class="rail-section-toggle" x-on:click="openSection = ! openSection" x-bind:aria-expanded="openSection.toString()">
        <h2 id="rail-topics-{{ $suffix }}" class="rail-title">{{ $placeName ? 'Topics in '.$placeName : 'Topic' }}</h2>
        <x-icon name="chevron-down" class="size-3.5 text-fg-subtle transition-transform" x-bind:class="! openSection && '-rotate-90'" />
    </button>
    <div class="rail-section-body" x-show="openSection">
        @if ($railTopics !== [])
            <ul class="rail-list" role="list">
                @foreach ($railTopics as $t)
                    @php
                        $shown = $t->shown();
                        $current = $topic?->id === $t->id;
                    @endphp
                    <li wire:key="rail-topic-{{ $suffix }}-{{ $t->id }}">
                        <button type="button" class="rail-row" wire:click="switchTopic('{{ $t->id }}')" @if ($current) aria-current="true" @endif @disabled(! $open)>
                            <span @class(['rail-dot', "is-{$shown}"]) aria-hidden="true"><x-icon :name="$statusIcons[$shown]" class="size-3.5" /></span>
                            <span class="min-w-0 flex-1 truncate">{{ $t->name }}</span>
                            <span class="sr-only">, {{ $statusWords[$shown] }}{{ $t->byTutor() ? ', marked by the tutor' : '' }}{{ $current ? ', this session is on it' : '' }}</span>
                            @if ($current)
                                <span class="rail-tag" aria-hidden="true">Now</span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        @elseif ($folder)
            <p class="rail-empty">No topics in this folder yet. The tutor adds them as you go, or you can on the folder's page.</p>
        @elseif ($module)
            <p class="rail-empty">No topics in this module yet. The tutor adds them as you go, or you can on the module's page.</p>
        @else
            <p class="rail-empty">{{ $topic ? $topic->name : 'This session has no topic yet.' }}</p>
        @endif
        @if ($open)
            <button type="button" @class(['btn btn-secondary btn-sm' => $railTopics === [], 'rail-more' => $railTopics !== []]) wire:click="editTopic">
                @if ($railTopics === [])<x-icon name="tag" class="size-4" />@endif{{ $railTopics !== [] ? 'Another topic…' : ($topic ? 'Change topic' : 'Choose a topic') }}
            </button>
        @endif
    </div>
</section>



<section class="rail-section" aria-labelledby="rail-material-{{ $suffix }}" x-data="{ openSection: true }">
    <button type="button" class="rail-section-toggle" x-on:click="openSection = ! openSection" x-bind:aria-expanded="openSection.toString()">
        <h2 id="rail-material-{{ $suffix }}" class="rail-title">Material</h2>
        <x-icon name="chevron-down" class="size-3.5 text-fg-subtle transition-transform" x-bind:class="! openSection && '-rotate-90'" />
    </button>
    <div class="rail-section-body" x-show="openSection">
        @if ($items === [])
            <p class="rail-empty">{{ $placeName ? 'Nothing in '.$placeName.' yet.' : 'No notes or files yet.' }}</p>
        @else
            <ul class="rail-list" role="list">
                @foreach ($items as $item)
                    @php
                        $used = $session->uses($item['key']);
                        $isNote = str_starts_with($item['key'], 'note:');
                    @endphp
                    <li class="rail-material" wire:key="rail-material-{{ $suffix }}-{{ $item['key'] }}">
                        <a class="rail-row" href="{{ $item['url'] }}" target="_blank" rel="noopener" x-on:click.prevent="$dispatch(@js($isNote ? 'session-open-note' : 'session-open-file'), { url: @js($item['url']), title: @js($item['name']), name: @js($item['name']) }); railOpen = false; $el.closest('dialog')?.close()">
                            <x-icon :name="$item['icon']" class="size-4 shrink-0" />
                            <span class="min-w-0 flex-1 truncate">{{ $item['name'] }}</span>
                        </a>
                        @if ($isNote)
                            <button type="button" class="rail-pin" x-on:click="$dispatch('session-open-note', { url: @js($item['url']), title: @js($item['name']) }); railOpen = false; $el.closest('dialog')?.close()" title="Open in Note pane" aria-label="Open note beside chat">
                                <x-icon name="notebook-pen" class="size-3.5" />
                            </button>
                        @else
                            <button type="button" class="rail-pin" x-on:click="$dispatch('session-open-file', { url: @js($item['url']), name: @js($item['name']) }); railOpen = false; $el.closest('dialog')?.close()" title="Open in File pane" aria-label="Open file beside chat">
                                <x-icon name="file-text" class="size-3.5" />
                            </button>
                        @endif
                        @if ($open)
                            <button type="button" class="rail-pin" wire:click="toggleMaterial('{{ $item['key'] }}')" aria-pressed="{{ $used ? 'true' : 'false' }}" title="The tutor reads it">
                                <x-icon :name="$used ? 'check' : 'plus'" class="size-4" /><span class="sr-only">The tutor reads {{ $item['name'] }}</span>
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($materialCount > count($items) || $usedCount > 0)
            <button type="button" class="rail-more" wire:click="openMaterial">All {{ $materialCount }}{{ $usedCount > 0 ? ' · '.$usedCount.' for the tutor' : '' }}</button>
        @endif
    </div>
</section>

<section class="rail-section" aria-labelledby="rail-session-{{ $suffix }}" x-data="{ openSection: true }">
    <button type="button" class="rail-section-toggle" x-on:click="openSection = ! openSection" x-bind:aria-expanded="openSection.toString()">
        <h2 id="rail-session-{{ $suffix }}" class="rail-title">This session</h2>
        <x-icon name="chevron-down" class="size-3.5 text-fg-subtle transition-transform" x-bind:class="! openSection && '-rotate-90'" />
    </button>
    <div class="rail-section-body" x-show="openSection">
        @if ($saved || $quizzed)
            <p class="rail-facts">{{ implode(' · ', array_filter([$saved, $quizzed])) }}</p>
        @elseif (($sessionQuestions ?? []) === [])
            <p class="rail-empty">Nothing saved yet. Ask the tutor to make cards or key points, or say "save that".</p>
        @endif
        @if (($sessionQuestions ?? []) !== [])
            <ul class="rail-list" role="list" aria-label="Questions from this session">
                @foreach ($sessionQuestions as $q)
                    <li wire:key="rail-q-{{ $suffix }}-{{ $q->id }}">
                        <button type="button" class="rail-row" wire:click="openQuestion('{{ $q->id }}')" title="{{ $q->answer ? 'View question & answer' : 'Get answer with AI' }}">
                            <x-icon :name="$q->status === 'answered' ? 'circle-check' : 'circle-help'" class="size-3.5 shrink-0" />
                            <span class="min-w-0 flex-1 truncate">{{ $q->text }}</span>
                            <span class="rail-tag">{{ $q->status === 'answered' ? 'Answered' : 'Ask AI' }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
