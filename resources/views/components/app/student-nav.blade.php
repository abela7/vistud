{{-- The student's sidebar outside a workspace: the courses, the calendar of every course, Settings, and every course. --}}
@props(['workspaces'])
<ul class="nav-list">
    <li>
        <a href="{{ route('home') }}" class="nav-item" title="All courses" @if (request()->routeIs('home')) aria-current="page" @endif>
            <x-icon name="house" /><span class="nav-label">All courses</span>
        </a>
    </li>
    <li>
        <a href="{{ route('calendar.index') }}" class="nav-item" title="The calendar of every course" @if (request()->routeIs('calendar.*')) aria-current="page" @endif>
            <x-icon name="calendar-days" /><span class="nav-label">Calendar</span>
        </a>
    </li>
    <li>
        <a href="{{ route('settings') }}" class="nav-item" title="Settings" @if (request()->routeIs('settings', 'journal.*', 'engine.settings')) aria-current="page" @endif>
            <x-icon name="settings" /><span class="nav-label">Settings</span>
        </a>
    </li>
</ul>
@if ($workspaces !== [])
    <p class="nav-section">Courses</p>
    <ul class="nav-list">
        @foreach ($workspaces as $workspace)
            <li>
                <a href="{{ route('workspaces.show', $workspace->id) }}" class="nav-item" title="{{ $workspace->name }}">
                    <x-workspace.chip :workspace="$workspace" size="sm" /><span class="nav-label truncate">{{ $workspace->name }}</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
