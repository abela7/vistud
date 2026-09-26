{{-- One assignment or task ($task), in App\Livewire\Workspaces\Tasks. --}}
@php
    $isDone = $task->status === 'done';
    $meta = array_filter([$task->kindLabel(), $task->moduleId !== null ? ($moduleTitles[$task->moduleId] ?? null) : null]);
@endphp
<li wire:key="task-{{ $task->id }}" class="task-row">
    <button type="button" class="task-check" wire:click="setStatus('{{ $task->id }}', '{{ $isDone ? 'todo' : 'done' }}')" aria-pressed="{{ $isDone ? 'true' : 'false' }}">
        <x-icon :name="$isDone ? 'circle-check' : 'circle'" class="size-5" />
        <span class="sr-only">Done: {{ $task->title }}</span>
    </button>
    <div class="min-w-0 flex-1">
        <p @class(['break-words', 'font-medium' => ! $isDone, 'text-fg-muted line-through' => $isDone])>{{ $task->title }}</p>
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-fg-muted">
            <span>{{ implode(' · ', $meta) }}</span>
            @if ($task->dueWords() && ! $isDone)
                <span @class(['status-chip', 'status-confused' => $task->overdue()])>
                    <x-icon :name="$task->overdue() ? 'circle-alert' : 'calendar-clock'" class="size-3.5" />{{ $task->dueWords() }}
                </span>
            @endif
            @if ($task->status === 'doing')
                <span class="status-chip status-covered"><x-icon name="clock" class="size-3.5" />In progress</span>
            @endif
        </p>
    </div>
    @include('livewire.workspaces.partials.row-menu', ['id' => $task->id, 'label' => $task->title, 'items' => [
        $task->status === 'doing'
            ? ['Back to to do', 'rotate-ccw', "setStatus('{$task->id}', 'todo')", false]
            : ['Start it', 'clock', "setStatus('{$task->id}', 'doing')", false],
        ['Edit', 'pencil', "editTask('{$task->id}')", false],
        ['Delete', 'trash-2', "confirmDelete('{$task->id}')", false],
    ]])
</li>
