{{--
    One step of the plan ($step), in App\Livewire\Workspaces\AssignmentPlan or SectionPage, with the steps under it (up to three
    levels). $depth is its level (1 for a step of a part), $first and $last say where it stands among its
    neighbours. A step with no steps is ticked: the circle is "done" (or back to "to do"), and the menu starts it, marks
    it stuck, or changes its details. A step that holds steps shows how far they are instead.
--}}
@php
    $state = $plan->stateOf($step);
    $kids = $plan->steps($step->id);
    $icons = ['todo' => 'circle', 'doing' => 'circle-dot', 'stuck' => 'circle-alert', 'done' => 'circle-check'];
    $actions = [];
    if ($kids === []) {
        foreach ([['doing', 'Start it', 'play'], ['stuck', 'I am stuck', 'circle-alert'], ['todo', 'Back to to do', 'circle'], ['done', 'Mark done', 'circle-check']] as [$to, $text, $icon]) {
            if ($state !== $to) {
                $actions[] = [$text, $icon, "setState('{$step->id}', '{$to}')", false];
            }
        }
    }
    $actions[] = ['Edit details', 'pencil', "startEdit('{$step->id}')", false];
    if ($depth < \App\Study\Plans::MAX_DEPTH) {
        $actions[] = ['Add a task under it', 'corner-down-right', "toggleSub('{$step->id}')", false];
    }
    $actions[] = ['Move up', 'arrow-up', "move('{$step->id}', 'up')", $first];
    $actions[] = ['Move down', 'arrow-down', "move('{$step->id}', 'down')", $last];
    $actions[] = [$kids === [] ? 'Delete' : 'Delete it and its tasks', 'trash-2', "remove('{$step->id}')", false];
@endphp
<li wire:key="plan-step-{{ $step->id }}" class="plan-step-item">
    @if ($editing === $step->id)
        @include('livewire.workspaces.partials.plan-edit', ['item' => $step])
    @else
        <div class="task-row plan-step">
            @if ($kids === [])
                <button type="button" class="task-check is-{{ $state }}" wire:click="setState('{{ $step->id }}', '{{ $state === 'done' ? 'todo' : 'done' }}')" aria-pressed="{{ $state === 'done' ? 'true' : 'false' }}">
                    <x-icon :name="$icons[$state]" class="size-5" />
                    <span class="sr-only">Done: {{ $step->title }}</span>
                </button>
            @else
                <span class="task-check is-{{ $state }}" aria-hidden="true"><x-icon :name="$icons[$state]" class="size-5" /></span>
            @endif
            <div class="plan-step-main">
                <p @class(['plan-step-title', 'text-fg-muted line-through' => $state === 'done'])>{{ $step->title }}@if ($kids !== []) <span class="plan-part-count tabular-nums">{{ $plan->counts($step)[0] }}/{{ $plan->counts($step)[1] }}</span>@endif</p>
                @include('livewire.workspaces.partials.plan-meta', ['item' => $step])
            </div>
            @include('livewire.workspaces.partials.row-menu', ['id' => 'plan-'.$step->id, 'label' => $step->title, 'items' => $actions])
        </div>
        @if ($kids !== [])
            <ul class="plan-steps plan-substeps" role="list" aria-label="Tasks of {{ $step->title }}">
                @foreach ($kids as $kid)
                    @include('livewire.workspaces.partials.plan-step', ['step' => $kid, 'first' => $loop->first, 'last' => $loop->last, 'depth' => $depth + 1])
                @endforeach
            </ul>
        @endif
        @if ($adding === $step->id)
            <form wire:submit="addStep('{{ $step->id }}')" novalidate class="plan-add plan-substeps" x-on:keydown.escape="$wire.toggleSub('{{ $step->id }}')">
                <label for="plan-step-{{ $step->id }}" class="sr-only">Add a task under {{ $step->title }}</label>
                <input id="plan-step-{{ $step->id }}" type="text" class="input" wire:model="stepText.{{ $step->id }}" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="A task under “{{ \Illuminate\Support\Str::limit($step->title, 30) }}”" autocomplete="off" autofocus>
                <button type="submit" class="btn btn-secondary btn-sm" aria-label="Add the task under {{ $step->title }}"><x-icon name="plus" class="size-4" /><span class="max-sm:sr-only">Add</span></button>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="toggleSub('{{ $step->id }}')">Done adding</button>
            </form>
            @error('stepText.'.$step->id) <p class="field-error plan-substeps">{{ $message }}</p> @enderror
        @endif
    @endif
</li>
