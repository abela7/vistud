{{-- Mockup: the sidebar inside a workspace. $current names the open section. --}}
@php
    $base = '/_mockups/workspaces/biology';
    $sections = [
        ['overview', 'Overview', 'layout-grid', $base],
        ['modules', 'Modules', 'layers', "{$base}/modules"],
        ['notes', 'Notes & files', 'file-text', "{$base}/modules"],
        ['calendar', 'Calendar', 'calendar', '#'],
        ['progress', 'Progress', 'trending-up', "{$base}/progress"],
    ];
@endphp
<div class="space-y-3">
    <a href="/_mockups/workspaces" class="ws-switcher no-underline" title="Switch workspace">
        <span class="ws-chip ws-colour-green"><x-icon name="microscope" class="size-4" /></span>
        <span class="min-w-0 flex-1">
            <span class="block text-xs text-fg-muted">Workspace</span>
            <span class="block truncate font-semibold">Biology</span>
        </span>
        <x-icon name="chevrons-up-down" class="size-4 text-fg-muted" />
    </a>
    <ul class="nav-list">
        @foreach ($sections as [$key, $label, $icon, $href])
            <li>
                <a href="{{ $href }}" class="nav-item" title="{{ $label }}" @if ($key === $current) aria-current="page" @endif>
                    <x-icon :name="$icon" /><span class="nav-label">{{ $label }}</span>
                </a>
            </li>
        @endforeach
    </ul>
    <div>
        <p class="nav-section">Everywhere</p>
        <ul class="nav-list">
            <li><a href="/_mockups/workspaces" class="nav-item"><x-icon name="house" /><span class="nav-label">All workspaces</span></a></li>
            <li><a href="#" class="nav-item"><x-icon name="folder" /><span class="nav-label">Personal</span></a></li>
        </ul>
    </div>
</div>
