{{--
    One topic on a module's or a folder's Topics tab, as a row of the list: its name, whole however long (opens the topic
    sheet), how it stands and its cards, then what to do with it: its page, and Study on a module. $t is the topic;
    $moduleId the module its cards are shown under on the Cards page.
--}}
@php
    $shown = $t->shown();
    $tc = $topicCards[$t->id] ?? null;
    $cleanName = \App\Study\Topics::cleanMarkdown($t->name);
@endphp
<li @class(['module-topic', "is-{$shown}"]) wire:key="module-topic-{{ $t->id }}">
    <button type="button" class="module-topic-name" x-on:click="Livewire.dispatch('topic-sheet-open', { topicId: '{{ $t->id }}' })" title="Open {{ $cleanName }}">{{ $cleanName }}</button>
    <div class="module-topic-meta">
        <button type="button" @class(['status-chip', "status-{$shown}"]) x-on:click="Livewire.dispatch('topic-sheet-open', { topicId: '{{ $t->id }}' })" title="Change how {{ $cleanName }} stands">
            <x-icon :name="$statusIcons[$shown]" class="size-3.5" />{{ $statusWords[$shown] }}
        </button>
        @if ($tc)
            <a href="{{ route('workspaces.show', [$workspaceId, 'flashcards', 'module' => $moduleId, 'topic' => $t->id]) }}" class="module-topic-cards" title="Review the cards of {{ $cleanName }}">
                <x-icon name="gallery-vertical-end" class="size-3.5" />{{ Str::plural('card', $tc['total'], prependCount: true) }}@if ($tc['due'] > 0)<span class="module-topic-due">{{ $tc['due'] }} due</span>@endif
            </a>
        @endif
    </div>
    <div class="module-topic-actions">
        <a href="{{ route('workspaces.topics.show', [$workspaceId, $t->id]) }}" class="btn btn-ghost btn-sm" title="Open the page of {{ $cleanName }}">
            <span>Topic page</span><x-icon name="arrow-right" class="size-3.5" />
        </a>
        @if (($isModule ?? false) && ! ($hideStudy ?? false))
            <x-button size="sm" icon="play" wire:click="studyTopic('{{ $t->id }}')" aria-label="Study {{ $cleanName }}">Study</x-button>
        @endif
    </div>
</li>
