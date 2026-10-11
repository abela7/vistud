{{--
    One topic on a module's or a folder's Topics tab: its name (opens the topic sheet), how it stands, its cards, and
    Study. $t is the topic; $moduleId the module its cards are shown under on the Cards page.
--}}
@php
    $shown = $t->shown();
    $tc = $topicCards[$t->id] ?? null;
    $cleanName = \App\Study\Topics::cleanMarkdown($t->name);
@endphp
<li class="module-topic" wire:key="module-topic-{{ $t->id }}">
    <div class="min-w-0 flex-1">
        <button type="button" class="font-medium break-words text-left hover:text-accent transition-colors cursor-pointer" x-on:click="Livewire.dispatch('topic-sheet-open', { topicId: '{{ $t->id }}' })">{{ $cleanName }}</button>
        <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-fg-muted mt-0.5">
            <button type="button" class="cursor-pointer" x-on:click="Livewire.dispatch('topic-sheet-open', { topicId: '{{ $t->id }}' })" title="Update status">
                <span @class(['status-chip', "status-{$shown}"])><x-icon :name="$statusIcons[$shown]" class="size-3.5" />{{ $statusWords[$shown] }}</span>
            </button>
            <a href="{{ route('workspaces.topics.show', [$workspaceId, $t->id]) }}" class="text-xs text-accent hover:opacity-80 font-medium inline-flex items-center gap-1" title="Open {{ $cleanName }} deep dive page" style="text-decoration: none !important;">
                <span>Topic page</span>
                <x-icon name="arrow-right" class="size-3" />
            </a>
            @if ($tc)
                <a href="{{ route('workspaces.show', [$workspaceId, 'flashcards', 'module' => $moduleId, 'topic' => $t->id]) }}" class="item-link" style="text-decoration: none !important;">{{ Str::plural('card', $tc['total'], prependCount: true) }}{{ $tc['due'] > 0 ? ', '.$tc['due'].' due' : '' }}</a>
            @endif
        </p>
    </div>
    @if (($isModule ?? false) && ! ($hideStudy ?? false))
        <x-button size="xs" icon="play" wire:click="studyTopic('{{ $t->id }}')" aria-label="Study {{ $cleanName }}" class="shrink-0">Study</x-button>
    @endif
</li>
