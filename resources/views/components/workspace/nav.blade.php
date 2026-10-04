{{--
    The sidebar inside a workspace: the switcher (the current workspace,
    opening a list of the others) and the workspace's sections. Drawn in the
    desktop sidebar and in the slide-in menu, so its menu ID is passed in. The
    list is a popover, so the sidebar (which scrolls, and collapses to icons)
    can't clip it: it opens out over the page from the switcher's left edge.
--}}
@props(['workspace', 'workspaces', 'section', 'menuId'])
<div class="space-y-3">
    <div class="ws-switcher-wrap">
        <button type="button" class="ws-switcher" data-menu-button aria-controls="{{ $menuId }}" aria-expanded="false" title="{{ $workspace->name }}: switch course">
            <x-workspace.chip :workspace="$workspace" />
            <span class="ws-switcher-text min-w-0 flex-1">
                <span class="block text-xs text-fg-muted">Course</span>
                <span class="block truncate font-semibold">{{ $workspace->name }}</span>
            </span>
            <x-icon name="chevrons-up-down" class="ws-switcher-caret size-4 text-fg-muted" />
        </button>
        <div id="{{ $menuId }}" class="ws-menu" data-menu-panel data-menu-align="start" popover="manual" hidden>
            @foreach ($workspaces as $other)
                <a href="{{ route('workspaces.show', $other->id) }}" class="menu-item" @if ($other->id === $workspace->id) aria-current="page" @endif>
                    <x-workspace.chip :workspace="$other" size="sm" /><span class="truncate">{{ $other->name }}</span>
                </a>
            @endforeach
            <div class="mt-1.5 border-t border-divider pt-1.5">
                <a href="{{ route('home') }}" class="menu-item"><x-icon name="house" class="size-4" />All courses</a>
                <a href="{{ route('workspaces.create') }}" class="menu-item"><x-icon name="plus" class="size-4" />New course</a>
            </div>
        </div>
    </div>
    <ul class="nav-list">
        @foreach (\App\Study\Workspaces::SECTIONS as [$key, $label, $icon])
            {{-- Six doors; Notes & files is reached from Modules and the course's Calendar from Home (docs/specs/vistud-2-blueprint.md §3.4). --}}
            @continue(! in_array($key, \App\Study\Workspaces::NAV, true))
            <li>
                <a href="{{ route('workspaces.show', $key === 'overview' ? $workspace->id : [$workspace->id, $key]) }}" class="nav-item" title="{{ $label }}" @if ($key === $section) aria-current="page" @endif>
                    <x-icon :name="$icon" /><span class="nav-label">{{ $label }}</span>
                </a>
            </li>
        @endforeach
    </ul>
    <div>
        <p class="nav-section">Everywhere</p>
        <ul class="nav-list">
            <li><a href="{{ route('home') }}" class="nav-item" title="All courses"><x-icon name="house" /><span class="nav-label">Courses</span></a></li>
            <li><a href="{{ route('calendar.index') }}" class="nav-item" title="The calendar of every course"><x-icon name="calendar-days" /><span class="nav-label">Calendar</span></a></li>
            <li><a href="{{ route('settings') }}" class="nav-item" title="Settings"><x-icon name="settings" /><span class="nav-label">Settings</span></a></li>
        </ul>
    </div>
</div>
