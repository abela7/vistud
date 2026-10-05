{{--
    One topic on a module's or a folder's Topics tab: its name (opens the topic sheet), how it stands, its cards, and
    Study. $t is the topic; $moduleId the module its cards are shown under on the Cards page.
--}}
@php
    $shown = $t->shown();
    $tc = $topicCards[$t->id] ?? null;
@endphp
<li class="module-topic" wire:key="module-topic-{{ $t->id }}">
    <div class="min-w-0 flex-1">
        <button type="button" class="font-medium break-words text-left" x-on:click="Livewire.dispatch('topic-sheet-open', { topicId: '{{ $t->id }}' })">{{ $t->name }}</button>
        <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-fg-muted">
            <span @class(['status-chip', "status-{$shown}"])><x-icon :name="$statusIcons[$shown]" class="size-3.5" />{{ $statusWords[$shown] }}</span>
            @if ($tc)
                <a href="{{ route('workspaces.show', [$workspaceId, 'flashcards', 'module' => $moduleId, 'topic' => $t->id]) }}" class="item-link">{{ Str::plural('card', $tc['total'], prependCount: true) }}{{ $tc['due'] > 0 ? ', '.$tc['due'].' due' : '' }}</a>
            @endif
        </p>
    </div>
    <x-button size="sm" icon="play" wire:click="studyTopic('{{ $t->id }}')" aria-label="Study {{ $t->name }}">Study</x-button>
</li>
