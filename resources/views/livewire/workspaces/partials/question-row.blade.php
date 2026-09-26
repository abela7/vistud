{{-- One registered question ($question), in the Progress section. --}}
@php $open = $question->shown() === 'open'; @endphp
<li wire:key="question-{{ $question->id }}" class="topic-row">
    <x-icon name="circle-help" class="mt-0.5 size-5 shrink-0 text-fg-muted" />
    <div class="topic-main">
        <p class="break-words">{{ $question->text }}</p>
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-fg-muted">
            @if ($question->topicId !== null && isset($topicNames[$question->topicId]))
                <span>{{ $topicNames[$question->topicId] }}</span>
                <span aria-hidden="true">·</span>
            @endif
            <span>asked {{ \Illuminate\Support\Carbon::parse($question->askedAt)->diffForHumans() }}</span>
            @if ($question->askTeacher)
                <span class="status-chip status-confused"><x-icon name="user-check" class="size-3.5" />Ask the teacher</span>
            @endif
            @if (in_array('reopened', $question->flags, true))
                <span class="status-chip status-not_started">Came back</span>
            @endif
        </p>
    </div>
    @if ($open)
        <x-button icon="circle-check" wire:click="resolveQuestion('{{ $question->id }}')" aria-label="I understand it now: {{ Str::limit($question->text, 60) }}">Understood</x-button>
    @else
        <x-button variant="ghost" icon="rotate-ccw" wire:click="reopenQuestion('{{ $question->id }}')" aria-label="Still not clear: {{ Str::limit($question->text, 60) }}">Still not clear</x-button>
    @endif
    @include('livewire.workspaces.partials.row-menu', ['id' => $question->id, 'label' => Str::limit($question->text, 60), 'items' => [
        [$question->askTeacher ? 'Not for the teacher' : 'Ask the teacher', 'user-check', "toggleAskTeacher('{$question->id}')", false],
        ['Remove', 'trash-2', "retireQuestion('{$question->id}')", false],
    ]])
</li>
