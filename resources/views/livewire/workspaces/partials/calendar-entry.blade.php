{{--
    One thing on a day of the calendar ($entry, App\Study\CalendarEntry), in App\Livewire\Workspaces\CalendarBoard: its
    icon in the colour of how it stands (late red, done green), its name as a link to where it lives, its time and
    detail, and in words how it stands. $global adds the workspace's name, for the calendar of every workspace.
--}}
@php
    $colour = $entry->late() ? 'red' : ($entry->done() ? 'green' : ['deadline' => 'blue', 'plan' => 'teal', 'cards' => 'purple', 'studied' => 'green'][$entry->source]);
    $word = $entry->late() ? ($entry->icon === 'flag' ? 'Missed' : 'Overdue') : ($entry->done() && $entry->source !== 'studied' ? 'Done' : null);
    $href = $entry->activityId !== null ? route('workspaces.assignments.show', [$entry->workspaceId, $entry->activityId]) : route('workspaces.show', [$entry->workspaceId, $entry->section]);
@endphp
<li class="item-row cal-entry">
    <span class="item-icon ws-colour-{{ $colour }}" aria-hidden="true"><x-icon :name="$entry->icon" class="size-5" /></span>
    <span class="min-w-0 flex-1">
        <a href="{{ $href }}" @class(['tile-link', 'text-fg-muted' => $entry->done() && $entry->source !== 'studied'])>{{ $entry->title }}</a>
        <span class="item-meta">{{ implode(' · ', array_filter([$entry->at, $entry->detail, $global ? $entry->workspaceName : null])) }}</span>
    </span>
    @if ($word)
        <span @class(['cal-state', "ws-colour-{$colour}"])>{{ $word }}</span>
    @endif
</li>
