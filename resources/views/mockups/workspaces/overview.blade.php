{{-- Mockup: inside the Biology workspace, its Overview. Static content for review. --}}
@php
    $panel = 'rounded-xl border border-border bg-surface-raised p-5 space-y-4';
@endphp
<x-layouts.app title="Biology">
    <x-slot:sidebar>@include('mockups.workspaces.sidebar', ['current' => 'overview'])</x-slot:sidebar>
    <x-slot:tabbar>@include('mockups.workspaces.tabbar', ['current' => 'overview'])</x-slot:tabbar>

    <div class="mx-auto max-w-5xl space-y-6">
        @include('mockups.workspaces.header', ['title' => 'Overview', 'action' => 'New note'])

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="{{ $panel }} lg:col-span-2">
                <h2 class="font-semibold">Coming up</h2>
                <ul class="space-y-3">
                    @foreach ([
                        ['Tomorrow, 10:00', 'Lecture', 'Photosynthesis'],
                        ['Thu 2 Oct', 'Quiz 2', 'Cell division'],
                        ['Fri 12 Dec', 'Final exam', 'Everything so far'],
                    ] as [$when, $what, $about])
                        <li class="flex items-start gap-3">
                            <span class="avatar shrink-0"><x-icon name="calendar" class="size-4" /></span>
                            <div>
                                <p class="font-medium">{{ $what }}: {{ $about }}</p>
                                <p class="text-sm text-fg-muted">{{ $when }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="{{ $panel }}">
                <h2 class="font-semibold">Worth revisiting</h2>
                <ul class="space-y-3">
                    <li>
                        <p class="flex flex-wrap items-center gap-2 font-medium">Mitosis vs meiosis <span class="badge badge-warning">Shaky</span></p>
                        <p class="text-sm text-fg-muted">Mixed up on Tuesday's practice quiz.</p>
                    </li>
                    <li>
                        <p class="flex flex-wrap items-center gap-2 font-medium">Chromosome number <span class="badge badge-warning">Shaky</span></p>
                        <p class="text-sm text-fg-muted">Not practised for 2 weeks.</p>
                    </li>
                </ul>
                <a href="/_mockups/workspaces/biology/progress" class="inline-flex items-center gap-1 text-sm font-medium">See progress <x-icon name="chevron-right" class="size-4" /></a>
            </section>

            <section class="{{ $panel }} lg:col-span-2">
                <h2 class="font-semibold">Pick up where you left off</h2>
                <ul class="divide-y divide-divider">
                    @foreach ([
                        ['file-text', 'Mitosis vs meiosis – my summary', 'Note · edited yesterday', '/_mockups/workspaces/biology/notes/mitosis-summary'],
                        ['file-up', 'Lecture 2 slides.pdf', 'File · added Monday', '#'],
                        ['image', 'Onion cells under the microscope.jpg', 'Photo · added last week', '#'],
                    ] as [$icon, $name, $meta, $href])
                        <li class="flex items-center gap-3 py-2.5">
                            <x-icon :name="$icon" class="size-5 shrink-0 text-fg-muted" />
                            <div class="min-w-0">
                                <a href="{{ $href }}" class="font-medium break-words text-fg">{{ $name }}</a>
                                <p class="text-sm text-fg-muted">{{ $meta }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="{{ $panel }}">
                <h2 class="font-semibold">Modules</h2>
                <ol class="space-y-3">
                    @foreach ([
                        ['Cells and organelles', 'done'],
                        ['Cell division', 'now'],
                        ['Photosynthesis', 'next'],
                        ['Genetics basics', 'later'],
                    ] as $i => [$module, $state])
                        <li class="flex items-center gap-3">
                            <x-icon :name="$state === 'done' ? 'circle-check' : 'circle-dot'" :class="'size-5 shrink-0 '.(['done' => 'text-success', 'now' => 'text-accent'][$state] ?? 'text-fg-muted')" />
                            <p @class(['font-semibold' => $state === 'now'])>Week {{ $i + 1 }}: {{ $module }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>
    </div>
</x-layouts.app>
