{{--
    The sidebar inside a workspace: the switcher (the current workspace,
    opening a list of the others) and the workspace's sections. Drawn in the
    desktop sidebar and in the slide-in menu, so its menu ID is passed in.
--}}
@props(['workspace', 'workspaces', 'section', 'menuId'])
<div class="space-y-3">
    <div class="ws-switcher-wrap">
        <button type="button" class="ws-switcher" data-menu-button aria-controls="{{ $menuId }}" aria-expanded="false" title="{{ $workspace->name }}: switch workspace">
            <x-workspace.chip :workspace="$workspace" />
            <span class="ws-switcher-text min-w-0 flex-1">
                <span class="block text-xs text-fg-muted">Workspace</span>
                <span class="block truncate font-semibold">{{ $workspace->name }}</span>
            </span>
            <x-icon name="chevrons-up-down" class="ws-switcher-caret size-4 text-fg-muted" />
        </button>
        <div id="{{ $menuId }}" class="ws-menu" data-menu-panel hidden>
            @foreach ($workspaces as $other)
                <a href="{{ route('workspaces.show', $other->id) }}" class="menu-item" @if ($other->id === $workspace->id) aria-current="page" @endif>
                    <x-workspace.chip :workspace="$other" size="sm" /><span class="truncate">{{ $other->name }}</span>
                </a>
            @endforeach
            <div class="mt-1.5 border-t border-divider pt-1.5">
                <a href="{{ route('home') }}" class="menu-item"><x-icon name="house" class="size-4" />All workspaces</a>
                <a href="{{ route('home', ['new' => 1]) }}" class="menu-item"><x-icon name="plus" class="size-4" />New workspace</a>
            </div>
        </div>
    </div>
    <ul class="nav-list">
        @foreach (\App\Study\Workspaces::SECTIONS as [$key, $label, $icon])
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
            <li><a href="{{ route('home') }}" class="nav-item" title="All workspaces"><x-icon name="house" /><span class="nav-label">All workspaces</span></a></li>
            <li><a href="{{ route('journal.index') }}" class="nav-item" title="Journal"><x-icon name="notebook-text" /><span class="nav-label">Journal</span></a></li>
        </ul>
    </div>
</div>
