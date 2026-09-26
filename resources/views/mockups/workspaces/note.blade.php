{{-- Mockup: a note open in the Biology workspace. Static content for review; the real editor is M2's biggest piece. --}}
<x-layouts.app title="Mitosis vs meiosis – my summary">
    <x-slot:sidebar>@include('mockups.workspaces.sidebar', ['current' => 'notes'])</x-slot:sidebar>
    <x-slot:tabbar>@include('mockups.workspaces.tabbar', ['current' => 'notes'])</x-slot:tabbar>

    <div class="mx-auto max-w-5xl space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <nav aria-label="Breadcrumb" class="text-sm text-fg-muted">
                <ol class="flex flex-wrap items-center gap-1.5">
                    <li><a href="/_mockups/workspaces/biology" class="text-fg-muted">Biology</a></li>
                    <li aria-hidden="true"><x-icon name="chevron-right" class="size-3.5" /></li>
                    <li><a href="/_mockups/workspaces/biology/modules" class="text-fg-muted">Week 2: Cell division</a></li>
                </ol>
            </nav>
            <p class="flex items-center gap-1.5 text-sm text-fg-muted" role="status"><x-icon name="check" class="size-4" />Saved</p>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_16rem]">
            <article class="rounded-xl border border-border bg-surface-raised">
                <div class="flex flex-wrap gap-1 border-b border-divider p-2" role="toolbar" aria-label="Formatting">
                    @foreach ([['bold', 'Bold'], ['italic', 'Italic'], ['heading-2', 'Heading'], ['list', 'Bulleted list']] as [$icon, $label])
                        <button type="button" class="topbar-button" aria-label="{{ $label }}" title="{{ $label }}"><x-icon :name="$icon" class="size-4" /></button>
                    @endforeach
                </div>
                <div class="space-y-4 px-5 py-6 sm:px-8">
                    <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Mitosis vs meiosis – my summary</h1>
                    <p>Both are ways a cell divides, but they have different jobs.</p>
                    <h2 class="text-lg font-semibold">Mitosis</h2>
                    <ul class="list-disc space-y-1 pl-6">
                        <li>For growth and repair, like healing a cut.</li>
                        <li>Makes <strong>2</strong> cells, each a copy of the original.</li>
                        <li>Same number of chromosomes as the parent cell (46 in humans).</li>
                    </ul>
                    <h2 class="text-lg font-semibold">Meiosis</h2>
                    <ul class="list-disc space-y-1 pl-6">
                        <li>Makes egg and sperm cells.</li>
                        <li>Makes <strong>4</strong> cells, each different.</li>
                        <li>Half the chromosomes (23 in humans).</li>
                    </ul>
                    <p class="rounded-lg bg-surface-sunken p-4">Trick to remember: <strong>meiosis</strong> makes <strong>me</strong> – the cells that make a new person.</p>
                </div>
            </article>

            <aside class="space-y-4">
                <section class="space-y-2 rounded-xl border border-border bg-surface-raised p-4">
                    <h2 class="text-sm font-semibold text-fg-muted">Topics in this note</h2>
                    <p class="flex flex-wrap items-center gap-2">Mitosis vs meiosis <span class="badge badge-warning">Shaky</span></p>
                    <p class="flex flex-wrap items-center gap-2">Cell cycle <span class="badge badge-accent">Learning</span></p>
                </section>
                <section class="space-y-2 rounded-xl border border-border bg-surface-raised p-4">
                    <h2 class="text-sm font-semibold text-fg-muted">Versions</h2>
                    <p class="text-sm">Today, 09:40 · you</p>
                    <p class="text-sm text-fg-muted">Yesterday, 18:12 · you</p>
                </section>
            </aside>
        </div>
    </div>
</x-layouts.app>
