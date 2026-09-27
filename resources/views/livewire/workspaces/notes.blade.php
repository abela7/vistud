{{--
    A workspace's Notes & files section (App\Livewire\Workspaces\Contents,
    view `notes`): what sits outside every module, its folders to open, and
    the trash.
--}}
@php
    use App\Study\Notes;
    use Illuminate\Support\Carbon;

    $top = "workspace:{$workspaceId}";
@endphp
<div class="space-y-6">
    <div class="flex justify-end">
        @include('livewire.workspaces.partials.new-menu', ['placeType' => 'workspace', 'placeId' => $workspaceId, 'primary' => true])
    </div>

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" />
    </div>

    @include('livewire.workspaces.partials.place', ['key' => $top, 'placeType' => 'workspace', 'placeId' => $workspaceId])

    <section aria-labelledby="trash-heading" class="space-y-2">
        <h2 id="trash-heading" class="sr-only">Trash</h2>
        @php $trashCount = count($trash) + count($trashedFiles); @endphp
        <x-button variant="ghost" icon="trash-2" wire:click="toggleTrash" aria-expanded="{{ $showTrash ? 'true' : 'false' }}" aria-controls="trash-list">
            {{ $showTrash ? 'Hide the trash' : 'Trash' }} ({{ $trashCount }})
        </x-button>
        @if ($showTrash)
            <div id="trash-list" class="module-card">
                @if ($trashCount === 0)
                    <p class="px-4 py-3 text-sm text-fg-muted">The trash is empty.</p>
                @else
                    <p class="border-b border-divider px-4 py-3 text-sm text-fg-muted">Deleted for good after {{ Notes::TRASH_DAYS }} days.</p>
                    <ul class="divide-y divide-divider" role="list">
                        @foreach ($trash as $note)
                            <li wire:key="trash-{{ $note->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3">
                                <x-icon name="file-text" class="size-5 shrink-0 text-fg-muted" />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium break-words">{{ $note->displayTitle() }}</span>
                                    <span class="block text-sm text-fg-muted">Note · trashed {{ Carbon::parse($note->trashedAt)->diffForHumans() }}</span>
                                </span>
                                <span class="flex flex-wrap gap-2">
                                    <x-button icon="rotate-ccw" wire:click="restoreNote('{{ $note->id }}')" aria-label="Restore {{ $note->displayTitle() }}">Restore</x-button>
                                    <x-button variant="ghost" wire:click="confirmDelete('note', '{{ $note->id }}')" aria-label="Delete for good: {{ $note->displayTitle() }}">Delete for good</x-button>
                                </span>
                            </li>
                        @endforeach
                        @foreach ($trashedFiles as $file)
                            <li wire:key="trash-{{ $file->id }}" class="flex flex-wrap items-center gap-3 px-4 py-3">
                                <x-icon :name="$file->icon()" class="size-5 shrink-0 text-fg-muted" />
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium break-words">{{ $file->fileName() }}</span>
                                    <span class="block text-sm text-fg-muted">{{ $file->typeLabel() }} · {{ $file->humanSize() }} · trashed {{ Carbon::parse($file->trashedAt)->diffForHumans() }}</span>
                                </span>
                                <span class="flex flex-wrap gap-2">
                                    <x-button icon="rotate-ccw" wire:click="restoreFile('{{ $file->id }}')" aria-label="Restore {{ $file->fileName() }}">Restore</x-button>
                                    <x-button variant="ghost" wire:click="confirmDelete('file', '{{ $file->id }}')" aria-label="Delete for good: {{ $file->fileName() }}">Delete for good</x-button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </section>

    @include('livewire.workspaces.partials.dialog')
</div>
