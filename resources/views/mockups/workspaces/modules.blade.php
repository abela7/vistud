{{-- Mockup: the Biology workspace's Modules, with the notes and files inside each. Static content for review. --}}
<x-layouts.app title="Modules · Biology">
    <x-slot:sidebar>@include('mockups.workspaces.sidebar', ['current' => 'modules'])</x-slot:sidebar>
    <x-slot:tabbar>@include('mockups.workspaces.tabbar', ['current' => 'modules'])</x-slot:tabbar>

    <div class="mx-auto max-w-4xl space-y-6">
        @include('mockups.workspaces.header', ['title' => 'Modules', 'action' => 'New module'])

        <ol class="space-y-3">
            @foreach ([
                ['Week 1', 'Cells and organelles', '8–14 Sep', '3 notes · 1 file', false],
                ['Week 2', 'Cell division', '15–21 Sep', '4 notes · 2 files', true],
                ['Week 3', 'Photosynthesis', '22–28 Sep', 'Empty', false],
                ['Week 4', 'Genetics basics', '29 Sep – 5 Oct', 'Empty', false],
            ] as [$week, $name, $dates, $summary, $open])
                <li class="rounded-xl border border-border bg-surface-raised">
                    <div class="flex flex-wrap items-center gap-3 px-5 py-4">
                        <x-icon :name="$open ? 'chevron-down' : 'chevron-right'" class="size-5 text-fg-muted" />
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ $week }}: {{ $name }}</p>
                            <p class="text-sm text-fg-muted">{{ $dates }} · {{ $summary }}</p>
                        </div>
                    </div>
                    @if ($open)
                        <ul class="divide-y divide-divider border-t border-divider">
                            @foreach ([
                                ['file-text', 'Mitosis vs meiosis – my summary', 'Note · edited yesterday', '/_mockups/workspaces/biology/notes/mitosis-summary', 0],
                                ['file-text', 'Lecture 2 – what the lecturer said', 'Note · Mon 15 Sep', '#', 0],
                                ['file-up', 'Lecture 2 slides.pdf', 'File · 2.4 MB', '#', 0],
                                ['folder', 'Practice quiz', 'Folder · 2 notes, 1 photo', '#', 0],
                                ['file-text', 'Quiz 1 – my mistakes', 'Note · inside Practice quiz', '#', 1],
                            ] as [$icon, $item, $meta, $href, $depth])
                                <li @class(['flex items-center gap-3 py-2.5 pr-5', 'pl-12' => $depth === 0, 'pl-20' => $depth === 1])>
                                    <x-icon :name="$icon" class="size-5 shrink-0 text-fg-muted" />
                                    <div class="min-w-0">
                                        <a href="{{ $href }}" class="font-medium break-words text-fg">{{ $item }}</a>
                                        <p class="text-sm text-fg-muted">{{ $meta }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        <div class="flex flex-wrap gap-2 border-t border-divider px-5 py-3 pl-12">
                            <x-button variant="ghost" icon="plus">Note</x-button>
                            <x-button variant="ghost" icon="folder">Folder</x-button>
                            <x-button variant="ghost" icon="file-up">Upload file</x-button>
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
</x-layouts.app>
