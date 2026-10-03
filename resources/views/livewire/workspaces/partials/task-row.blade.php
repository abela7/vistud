{{--
    One assignment or task ($task) on the Overview's Coming up (App\Livewire\Workspaces\Tasks): the circle ticks it
    (a dot while it is in progress), its name opens its page, a line says what it is and how far its plan has got, and
    on the right how long is left (red when late, amber within two days) with its date under it.
--}}
@php
    $isDone = $task->status === 'done';
    $plan = $isDone ? null : ($plans[$task->id] ?? null);
    $health = $isDone ? null : ($healths[$task->id] ?? null);
    $meta = array_filter([
        $task->kindLabel(),
        $task->moduleId !== null ? ($moduleTitles[$task->moduleId] ?? null) : null,
        $task->status === 'doing' && ($health === null || $health['state'] === 'on_track') ? 'In progress' : null,
    ]);
    $urgency = match (true) {
        $isDone || $task->dueOn === null => null,
        $task->overdue() => 'red',
        $task->dueAt()->lessThan(now()->addDays(2)) => 'amber',
        default => null,
    };
@endphp
<li wire:key="task-{{ $task->id }}" class="item-row coming-row"
    data-select-key="task:{{ $task->id }}"
    :class="{ 'is-selected': isSelected('task:{{ $task->id }}') }"
    x-on:click="handleRowClick($event, 'task:{{ $task->id }}')">
    <x-selection-check key="task:{{ $task->id }}" label="Select {{ $task->title }}" />
    <button type="button" class="task-check is-{{ $task->status }}" x-show="!isSelecting" wire:click="setStatus('{{ $task->id }}', '{{ $isDone ? 'todo' : 'done' }}')" aria-pressed="{{ $isDone ? 'true' : 'false' }}">
        <x-icon :name="match ($task->status) { 'done' => 'circle-check', 'doing' => 'circle-dot', default => 'circle' }" class="size-5" />
        <span class="sr-only">Done: {{ $task->title }}</span>
    </button>
    <span class="min-w-0 flex-1">
        <a href="{{ route('workspaces.assignments.show', [$task->workspaceId, $task->id]) }}" @class(['tile-link', 'coming-title', 'is-done' => $isDone])>{{ $task->title }}</a>
        <span class="coming-meta">
            <span>{{ implode(' · ', $meta) }}</span>
            @if ($plan)
                <span class="coming-plan" title="{{ $plan->percent }}% of the plan">
                    <span class="meter" aria-hidden="true"><span style="width: {{ $plan->percent }}%"></span></span>{{ $plan->percent }}%<span class="sr-only"> of the plan</span>
                </span>
            @endif
            @if ($health && $health['state'] !== 'on_track')
                <x-workspace.plan-health :health="$health" />
            @endif
        </span>
    </span>
    @if (! $isDone && $task->dueOn !== null)
        <span @class(['coming-due', "ws-colour-{$urgency}" => $urgency !== null])>
            <span class="coming-left"><x-icon :name="$task->overdue() ? 'circle-alert' : 'hourglass'" class="size-3.5" />{{ $task->timeLeft() }}</span>
            <span class="coming-day">{{ $task->dueWords() }}</span>
        </span>
    @endif
    @include('livewire.workspaces.partials.row-menu', ['id' => $task->id, 'label' => $task->title, 'items' => [
        $task->status === 'doing'
            ? ['Back to to do', 'rotate-ccw', "setStatus('{$task->id}', 'todo')", false]
            : ['Start it', 'clock', "setStatus('{$task->id}', 'doing')", false],
        ['Edit', 'pencil', "editTask('{$task->id}')", false],
        ['Delete', 'trash-2', "confirmDelete('{$task->id}')", false],
    ]])
</li>
