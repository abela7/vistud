{{--
    What an item of the plan carries, as small chips under its name (App\Livewire\Workspaces\AssignmentPlan): where it
    is (doing or stuck), its dates (red once past them and not done), priority, the person it is for, its labels and
    its notes. $item is the item, $plan the whole plan, $today the student's day. Nothing is shown for what is not set.
--}}
@php
    $state = $plan->stateOf($item);
    $when = $item->when();
    $late = $plan->overdue($item, $today);
    // Priority, the person it is for and labels are the course's project tools.
    $tools = $projectTools ?? false;
    $person = $tools && $item->memberId !== null ? $plan->member($item->memberId) : null;
    $priorities = ['low' => ['Low', 'blue'], 'medium' => ['Medium', 'teal'], 'high' => ['High', 'amber'], 'urgent' => ['Urgent', 'red']];
@endphp
@php
    // A section's own bar already says how far it is: only "stuck" is worth a chip there.
    $chipState = $item->kind === 'part' && $state === 'doing' ? null : $state;
@endphp
@if (in_array($chipState, ['doing', 'stuck'], true) || $when || ($tools && ($item->priority || $item->labels !== [])) || $person)
    <p class="plan-meta">
        @if ($chipState === 'doing')
            <span class="plan-chip ws-colour-amber"><x-icon name="circle-dot" class="size-3.5" />In progress</span>
        @elseif ($chipState === 'stuck')
            <span class="plan-chip ws-colour-red"><x-icon name="circle-alert" class="size-3.5" />Stuck</span>
        @endif
        @if ($when)
            <span @class(['plan-chip', 'ws-colour-red' => $late])><x-icon :name="$late ? 'calendar-clock' : 'calendar'" class="size-3.5" />{{ $late ? ($item->kind === 'milestone' ? 'Missed · ' : 'Overdue · ') : '' }}{{ $when }}</span>
        @endif
        @if ($tools && $item->priority)
            <span class="plan-chip ws-colour-{{ $priorities[$item->priority][1] }}"><x-icon name="flag" class="size-3.5" />{{ $priorities[$item->priority][0] }}</span>
        @endif
        @if ($person)
            <span class="plan-chip"><x-icon name="user-round" class="size-3.5" />{{ $person->name }}</span>
        @endif
        @if ($tools)
            @foreach ($item->labels as $label)
                <span class="plan-chip"><x-icon name="tag" class="size-3.5" />{{ $label }}</span>
            @endforeach
        @endif
    </p>
@endif
@if ($item->notes)
    <p class="plan-notes">{{ $item->notes }}</p>
@endif
