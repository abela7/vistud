{{-- App\Livewire\Workspaces\Index: the student's workspaces as cards, then the archived ones. --}}
@php
    use Illuminate\Support\Carbon;
    $dates = fn ($w) => $w->startsOn || $w->endsOn
        ? trim(($w->startsOn ? Carbon::parse($w->startsOn)->format('j M Y') : '').' – '.($w->endsOn ? Carbon::parse($w->endsOn)->format('j M Y') : ''), ' –')
        : null;
@endphp
<div class="space-y-8">
    <div role="status" aria-live="polite">
        <x-toast :message="$notice" />
    </div>

    @if ($active === [])
        <section class="flex flex-col items-center gap-4 rounded-xl border border-dashed border-border-strong px-6 py-12 text-center">
            <span class="ws-chip size-14"><x-icon name="layout-grid" class="size-6" /></span>
            <div class="space-y-1">
                <h2 class="text-lg font-semibold">Create your first course</h2>
                <p class="max-w-md text-fg-muted">One course per subject, like Biology or Spanish. Its modules, notes, files, calendar and progress all live inside it.</p>
            </div>
            <a href="{{ route('workspaces.create') }}" class="btn btn-primary"><x-icon name="plus" class="size-4" />New course</a>
        </section>
    @else
        <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4" role="list">
            @foreach ($active as $workspace)
                @php $wsCounts = $counts[$workspace->id] ?? ['modules' => 0, 'notes' => 0, 'files' => 0]; @endphp
                <li wire:key="ws-{{ $workspace->id }}" class="workspace-card ws-colour-{{ $workspace->colour }} group">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <x-workspace.chip :workspace="$workspace" size="lg" />
                            <div class="min-w-0">
                                <h2 class="font-semibold tracking-tight break-words">
                                    <a href="{{ route('workspaces.show', $workspace->id) }}" class="tile-link">{{ $workspace->name }}</a>
                                </h2>
                                @if ($workspace->subtitle() !== '')
                                    <p class="item-meta break-words">{{ $workspace->subtitle() }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="relative shrink-0">
                            <button type="button" class="topbar-button" wire:ignore.self data-menu-button aria-controls="ws-menu-{{ $workspace->id }}" aria-expanded="false" title="Actions">
                                <x-icon name="ellipsis" class="size-5" />
                                <span class="sr-only">Actions for {{ $workspace->name }}</span>
                            </button>
                            <div id="ws-menu-{{ $workspace->id }}" class="row-menu" wire:ignore.self data-menu-panel popover="manual" hidden>
                                <button type="button" class="menu-item" x-data x-on:click="$dispatch('workspace-edit', { workspaceId: '{{ $workspace->id }}' })">
                                    <x-icon name="pencil" class="size-4" />Edit course
                                </button>
                                <button type="button" class="menu-item" wire:click="archive('{{ $workspace->id }}')">
                                    <x-icon name="archive" class="size-4" />Archive course
                                </button>
                                <button type="button" class="menu-item text-danger" wire:click="confirmDelete('{{ $workspace->id }}')">
                                    <x-icon name="trash-2" class="size-4" />Delete course
                                </button>
                            </div>
                        </div>
                    </div>

                    @if ($dates($workspace))
                        <p class="flex items-center gap-1.5 text-xs font-medium text-fg-muted">
                            <x-icon name="calendar" class="size-3.5 shrink-0" />
                            <span>{{ $dates($workspace) }}</span>
                        </p>
                    @endif

                    <div class="mt-auto flex items-center justify-between gap-2 border-t border-divider pt-3 text-xs text-fg-muted">
                        <div class="flex flex-wrap items-center gap-2.5">
                            @if ($wsCounts['modules'] > 0)
                                <span class="inline-flex items-center gap-1">
                                    <x-icon name="layers" class="size-3.5" />{{ $wsCounts['modules'] }} {{ $wsCounts['modules'] === 1 ? 'module' : 'modules' }}
                                </span>
                            @endif
                            @if ($wsCounts['notes'] > 0)
                                <span class="inline-flex items-center gap-1">
                                    <x-icon name="file-text" class="size-3.5" />{{ $wsCounts['notes'] }} {{ $wsCounts['notes'] === 1 ? 'note' : 'notes' }}
                                </span>
                            @endif
                            @if ($wsCounts['files'] > 0)
                                <span class="inline-flex items-center gap-1">
                                    <x-icon name="file" class="size-3.5" />{{ $wsCounts['files'] }} {{ $wsCounts['files'] === 1 ? 'file' : 'files' }}
                                </span>
                            @endif
                            @if ($wsCounts['modules'] === 0 && $wsCounts['notes'] === 0 && $wsCounts['files'] === 0)
                                <span class="text-fg-subtle">Empty course</span>
                            @endif
                        </div>
                        <x-icon name="arrow-right" class="size-4 shrink-0 text-fg-subtle transition-transform group-hover:translate-x-0.5" />
                    </div>
                </li>
            @endforeach
            <li>
                <a href="{{ route('workspaces.create') }}" class="group flex h-full min-h-[10.5rem] w-full flex-col items-center justify-center gap-2.5 rounded-xl border border-dashed border-border-strong p-5 text-fg-muted transition-colors hover:border-fg-muted hover:bg-hover hover:text-fg">
                    <span class="flex size-10 items-center justify-center rounded-full bg-surface-sunken transition-transform group-hover:scale-105">
                        <x-icon name="plus" class="size-5" />
                    </span>
                    <span class="text-sm font-semibold">New course</span>
                </a>
            </li>
        </ul>
    @endif

    @if ($archived !== [])
        <details class="group space-y-3">
            <summary class="inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-fg-muted">
                <x-icon name="chevron-right" class="size-4 transition-transform group-open:rotate-90" />Archived ({{ count($archived) }})
            </summary>
            <ul class="account-list mt-3 divide-y divide-divider" role="list">
                @foreach ($archived as $workspace)
                    <li wire:key="archived-{{ $workspace->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5">
                        <x-workspace.chip :workspace="$workspace" />
                        <a href="{{ route('workspaces.show', $workspace->id) }}" class="min-w-0 flex-1 font-medium break-words text-fg">{{ $workspace->name }}</a>
                        <div class="flex items-center gap-2">
                            <x-button variant="ghost" icon="archive-restore" wire:click="restore('{{ $workspace->id }}')">
                                Restore<span class="sr-only"> {{ $workspace->name }}</span>
                            </x-button>
                            <x-button variant="ghost" icon="trash-2" class="text-danger" wire:click="confirmDelete('{{ $workspace->id }}')">
                                Delete<span class="sr-only"> {{ $workspace->name }}</span>
                            </x-button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <dialog id="workspace-delete-dialog" class="modal" aria-labelledby="workspace-delete-title"
        wire:ignore.self
        x-data
        x-on:workspace-delete-dialog-open.window="$el.showModal()"
        x-on:workspace-delete-dialog-close.window="$el.close()"
        x-on:close="$wire.cancelDelete()"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel max-w-md">
            <div class="modal-head">
                <div class="min-w-0 flex-1 space-y-1">
                    <h2 id="workspace-delete-title" class="text-lg font-semibold text-danger">Delete course</h2>
                    <p class="text-sm text-fg-muted">This action cannot be undone.</p>
                </div>
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                    <x-icon name="x" />
                </button>
            </div>
            <div class="space-y-3 px-5 py-4">
                <p class="text-sm text-fg">
                    Are you sure you want to delete <strong class="font-semibold text-fg">{{ $deletingName }}</strong>?
                </p>
                <p class="text-xs text-fg-muted">
                    Everything in this course will be deleted permanently, including all modules, notes, uploaded files, and study records.
                </p>
            </div>
            <div class="modal-actions">
                <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                <x-button variant="danger" icon="trash-2" wire:click="delete" wire:loading.attr="aria-busy" wire:target="delete" busy-label="Deleting…">Delete course</x-button>
            </div>
        </div>
    </dialog>
</div>
