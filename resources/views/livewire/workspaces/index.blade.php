{{-- App\Livewire\Workspaces\Index: the student's workspaces as cards, then the archived ones. --}}
@php
    use Illuminate\Support\Carbon;
    $dates = fn ($w) => $w->startsOn || $w->endsOn
        ? trim(($w->startsOn ? Carbon::parse($w->startsOn)->format('j M Y') : '').' – '.($w->endsOn ? Carbon::parse($w->endsOn)->format('j M Y') : ''), ' –')
        : null;
@endphp
<div class="space-y-8">
    <div role="status" aria-live="polite">
        @if ($notice)
            <x-alert tone="success" :live="false">{{ $notice }}</x-alert>
        @endif
    </div>

    @if ($active === [])
        <section class="flex flex-col items-center gap-4 rounded-xl border border-dashed border-border-strong px-6 py-12 text-center">
            <span class="ws-chip size-14"><x-icon name="layout-grid" class="size-6" /></span>
            <div class="space-y-1">
                <h2 class="text-lg font-semibold">Create your first workspace</h2>
                <p class="max-w-md text-fg-muted">One workspace per subject, like Biology or Spanish. Its modules, notes, files, calendar and progress all live inside it.</p>
            </div>
            <x-button variant="primary" icon="plus" x-data x-on:click="$dispatch('workspace-form-open')">New workspace</x-button>
        </section>
    @else
        <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4" role="list">
            @foreach ($active as $workspace)
                <li wire:key="ws-{{ $workspace->id }}" class="relative flex flex-col gap-3 rounded-xl border border-border bg-surface-raised p-5 elevation-sm hover:border-border-strong">
                    <div class="flex items-start gap-3">
                        <x-workspace.chip :workspace="$workspace" size="lg" />
                        <div class="min-w-0">
                            <h2 class="font-semibold break-words">
                                <a href="{{ route('workspaces.show', $workspace->id) }}" class="text-fg no-underline after:absolute after:inset-0 after:rounded-xl">{{ $workspace->name }}</a>
                            </h2>
                            @if ($workspace->subtitle() !== '')
                                <p class="text-sm break-words text-fg-muted">{{ $workspace->subtitle() }}</p>
                            @endif
                        </div>
                    </div>
                    @if ($dates($workspace))
                        <p class="flex items-center gap-2 text-sm text-fg-muted"><x-icon name="calendar" class="size-4" />{{ $dates($workspace) }}</p>
                    @endif
                </li>
            @endforeach
            <li>
                <button type="button" class="flex h-full min-h-32 w-full flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border-strong p-5 text-fg-muted hover:bg-hover hover:text-fg" x-data x-on:click="$dispatch('workspace-form-open')">
                    <x-icon name="plus" class="size-6" />
                    <span class="font-medium">New workspace</span>
                </button>
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
                        <x-button variant="ghost" icon="archive-restore" wire:click="restore('{{ $workspace->id }}')">
                            Restore<span class="sr-only"> {{ $workspace->name }}</span>
                        </x-button>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
