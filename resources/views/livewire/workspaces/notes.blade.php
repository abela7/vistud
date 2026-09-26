{{--
    A workspace's Notes & files section (App\Livewire\Workspaces\Contents,
    view `notes`): the notes edited most recently, what sits outside every
    module, and the trash. Files arrive with step 4.
--}}
@php
    use App\Study\Notes;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $top = "workspace:{$workspaceId}";
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-fg-muted">{{ $noteCount }} {{ Str::plural('note', $noteCount) }}</p>
        <div class="flex flex-wrap gap-2">
            <x-button icon="folder-plus" wire:click="newFolder('workspace', '{{ $workspaceId }}')">New folder</x-button>
            <x-button variant="primary" icon="file-plus" wire:click="newNote('workspace', '{{ $workspaceId }}')">New note</x-button>
        </div>
    </div>

    <div role="status" aria-live="polite">
        @if ($notice)
            <x-alert tone="success" :live="false">{{ $notice }}</x-alert>
        @endif
    </div>

    {{-- On wide screens, the recent notes sit beside everything else. --}}
    <div @class(['grid gap-6', 'xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)] xl:items-start' => $recent !== []])>
    @if ($recent !== [])
        <section aria-labelledby="recent-heading" class="space-y-2 xl:col-start-2 xl:row-start-1">
            <h2 id="recent-heading" class="text-lg font-semibold">Recently edited</h2>
            <ul class="module-card divide-y divide-divider" role="list">
                @foreach ($recent as $note)
                    <li wire:key="recent-{{ $note->id }}" class="flex items-center gap-3 px-4 py-3">
                        <x-icon name="file-text" class="size-5 shrink-0 text-fg-muted" />
                        <span class="min-w-0 flex-1">
                            <a href="{{ route('workspaces.notes.show', [$note->workspaceId, $note->id]) }}" class="item-link">{{ $note->displayTitle() }}</a>
                            <span class="block text-sm break-words text-fg-muted">{{ $places[$note->placeKey()] ?? '' }} · edited {{ Carbon::parse($note->updatedAt)->diffForHumans() }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="space-y-6 xl:col-start-1 xl:row-start-1">
    <section aria-labelledby="loose-heading" class="space-y-2">
        <h2 id="loose-heading" class="text-lg font-semibold">Not in a module</h2>
        <div class="module-card px-3 py-2">
            @include('livewire.workspaces.partials.place', ['key' => $top])
            @unless (isset($children[$top]) || isset($notesIn[$top]))
                <p class="px-2 py-3 text-sm text-fg-muted">Notes and folders that don't belong to one module, like exam revision or a reading list, go here. Notes inside modules are in Modules.</p>
            @endunless
        </div>
    </section>

    <section aria-labelledby="trash-heading" class="space-y-2">
        <h2 id="trash-heading" class="sr-only">Trash</h2>
        <x-button variant="ghost" icon="trash-2" wire:click="toggleTrash" aria-expanded="{{ $showTrash ? 'true' : 'false' }}" aria-controls="trash-list">
            {{ $showTrash ? 'Hide the trash' : 'Trash' }} ({{ count($trash) }})
        </x-button>
        @if ($showTrash)
            <div id="trash-list" class="module-card">
                @if ($trash === [])
                    <p class="px-4 py-3 text-sm text-fg-muted">The trash is empty.</p>
                @else
                    <p class="border-b border-divider px-4 py-3 text-sm text-fg-muted">Notes in the trash are deleted for good {{ Notes::TRASH_DAYS }} days after they were trashed.</p>
                    <ul class="divide-y divide-divider" role="list">
                        @foreach ($trash as $note)
                            <li wire:key="trash-{{ $note->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3">
                                <x-icon name="file-text" class="size-5 shrink-0 text-fg-muted" />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium break-words">{{ $note->displayTitle() }}</span>
                                    <span class="block text-sm text-fg-muted">Trashed {{ Carbon::parse($note->trashedAt)->diffForHumans() }}</span>
                                </span>
                                <span class="flex flex-wrap gap-2">
                                    <x-button icon="rotate-ccw" wire:click="restoreNote('{{ $note->id }}')" aria-label="Restore {{ $note->displayTitle() }}">Restore</x-button>
                                    <x-button variant="ghost" wire:click="confirmDelete('note', '{{ $note->id }}')" aria-label="Delete for good: {{ $note->displayTitle() }}">Delete for good</x-button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </section>
    </div>
    </div>

    @include('livewire.workspaces.partials.dialog')
</div>
