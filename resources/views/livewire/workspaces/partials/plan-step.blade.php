{{-- One step of the plan ($step), in App\Livewire\Workspaces\AssignmentPlan. $first and $last say where it stands among the steps beside it. --}}
<li wire:key="plan-step-{{ $step->id }}" class="task-row plan-step">
    @if ($editing === $step->id)
        @include('livewire.workspaces.partials.plan-edit', ['item' => $step, 'marks' => false])
    @else
        <button type="button" class="task-check" wire:click="setState('{{ $step->id }}', '{{ $step->done() ? 'todo' : 'done' }}')" aria-pressed="{{ $step->done() ? 'true' : 'false' }}">
            <x-icon :name="$step->done() ? 'circle-check' : 'circle'" class="size-5" />
            <span class="sr-only">Done: {{ $step->title }}</span>
        </button>
        <p @class(['text-fg-muted line-through' => $step->done()])>{{ $step->title }}</p>
        @include('livewire.workspaces.partials.row-menu', ['id' => 'plan-'.$step->id, 'label' => $step->title, 'items' => [
            ['Edit', 'pencil', "startEdit('{$step->id}')", false],
            ['Move up', 'arrow-up', "move('{$step->id}', 'up')", $first],
            ['Move down', 'arrow-down', "move('{$step->id}', 'down')", $last],
            ['Delete', 'trash-2', "remove('{$step->id}')", false],
        ]])
    @endif
</li>
