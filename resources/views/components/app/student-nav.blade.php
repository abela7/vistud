{{-- The student's sidebar outside a workspace: home, the journal, and every workspace. --}}
@props(['workspaces'])
<ul class="nav-list">
    <li>
        <a href="{{ route('home') }}" class="nav-item" title="All workspaces" @if (request()->routeIs('home')) aria-current="page" @endif>
            <x-icon name="house" /><span class="nav-label">All workspaces</span>
        </a>
    </li>
    <li>
        <a href="{{ route('journal.index') }}" class="nav-item" title="Journal" @if (request()->routeIs('journal.*')) aria-current="page" @endif>
            <x-icon name="notebook-text" /><span class="nav-label">Journal</span>
        </a>
    </li>
</ul>
@if ($workspaces !== [])
    <p class="nav-section">Workspaces</p>
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
