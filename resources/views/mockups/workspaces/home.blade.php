{{-- Mockup: "My workspaces", the student's home. Static content for review (docs/specs/workspaces.md). --}}
@php
    $workspaces = [
        ['Biology', 'BIO101 · Autumn 2026', 'microscope', 'ws-colour-green', 'Quiz 2 on Thursday', '4 modules · 9 notes · 3 files', '/_mockups/workspaces/biology'],
        ['Mathematics', 'Linear algebra · Autumn 2026', 'sigma', 'ws-colour-blue', 'Problem set 3 due Monday', '5 modules · 14 notes', '#'],
        ['Spanish', 'Self-study · A2 level', 'languages', 'ws-colour-amber', 'Nothing scheduled', '2 modules · 6 notes', '#'],
    ];
@endphp
<x-layouts.app title="My workspaces">
    <x-slot:sidebar>
        <ul class="nav-list">
            <li><a href="/_mockups/workspaces" class="nav-item" aria-current="page"><x-icon name="house" /><span class="nav-label">All workspaces</span></a></li>
            <li><a href="#" class="nav-item"><x-icon name="folder" /><span class="nav-label">Personal</span></a></li>
            <li><a href="#" class="nav-item"><x-icon name="calendar" /><span class="nav-label">Calendar</span></a></li>
        </ul>
        <p class="nav-section">Workspaces</p>
        <ul class="nav-list">
            @foreach ($workspaces as [$name, , $icon, $tone, , , $href])
                <li>
                    <a href="{{ $href }}" class="nav-item" title="{{ $name }}">
                        <span class="ws-chip {{ $tone }} size-6"><x-icon :name="$icon" class="size-3.5" /></span><span class="nav-label">{{ $name }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </x-slot:sidebar>

    <div class="mx-auto max-w-5xl space-y-8">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">My workspaces</h1>
                <p class="max-w-2xl text-fg-muted">Each subject gets its own space: modules, notes, files, calendar and your progress.</p>
            </div>
            <x-button variant="primary" icon="plus">New workspace</x-button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($workspaces as [$name, $meta, $icon, $tone, $next, $counts, $href])
                <section class="relative flex flex-col gap-4 rounded-xl border border-border bg-surface-raised p-5 elevation-sm">
                    <div class="flex items-start gap-3">
                        <span class="ws-chip {{ $tone }}"><x-icon :name="$icon" class="size-5" /></span>
                        <div class="min-w-0">
                            <h2 class="font-semibold"><a href="{{ $href }}" class="text-fg no-underline after:absolute after:inset-0 after:rounded-xl">{{ $name }}</a></h2>
                            <p class="text-sm text-fg-muted">{{ $meta }}</p>
                        </div>
                    </div>
                    <p class="flex items-center gap-2 text-sm"><x-icon name="clock" class="size-4 text-fg-muted" />{{ $next }}</p>
                    <p class="mt-auto border-t border-divider pt-3 text-sm text-fg-muted">{{ $counts }}</p>
                </section>
            @endforeach
            <button type="button" class="flex min-h-40 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border-strong p-5 text-fg-muted hover:bg-hover">
                <x-icon name="plus" class="size-6" />
                <span class="font-medium">New workspace</span>
            </button>
        </div>
    </div>
</x-layouts.app>
