{{-- The one dialog of App\Livewire\Workspaces\Contents: a module, a folder, renaming a file, uploading, a move, or deleting. --}}
@php
    $headings = [
        'file' => 'Rename file',
        'upload' => 'Upload files'.($target ? ' to '.$target : ''),
        'module' => $creating ? 'New module' : 'Edit module',
        'folder' => $creating ? 'New folder'.($target ? ' in '.$target : '') : 'Rename folder',
        'move' => 'Move “'.$target.'”',
        'delete' => in_array($targetType, ['note', 'file'], true) ? 'Delete “'.$target.'” for good?' : 'Delete “'.$target.'”?',
    ];
    $submit = ['module' => $creating ? 'Add module' : 'Save', 'folder' => $creating ? 'Add folder' : 'Rename', 'file' => 'Rename', 'upload' => 'Upload', 'move' => 'Move', 'delete' => in_array($targetType, ['note', 'file'], true) ? 'Delete for good' : 'Delete'];
@endphp
<dialog id="structure-dialog" class="modal" aria-labelledby="structure-dialog-title"
    wire:ignore.self
    x-data
    x-on:structure-dialog-open.window="$el.open || $el.showModal()"
    x-on:structure-dialog-close.window="$el.open && $el.close()"
    x-on:close="$wire.mode && $wire.close()"
    x-on:click="$event.target === $el && $el.close()">
    @if ($mode)
        <form wire:submit="save" novalidate class="modal-panel" wire:key="dialog-{{ $mode }}-{{ $targetId }}-{{ $creating ? 'new' : 'edit' }}"
            x-data="{ progress: null, failed: false }"
            x-on:livewire-upload-start="progress = 0; failed = false"
            x-on:livewire-upload-progress="progress = $event.detail.progress"
            x-on:livewire-upload-finish="progress = null"
            x-on:livewire-upload-error="progress = null; failed = true">
            <div class="modal-head">
                <h2 id="structure-dialog-title" class="min-w-0 flex-1 text-lg font-semibold break-words" @if (in_array($mode, ['move', 'delete'], true)) tabindex="-1" autofocus @endif>{{ $headings[$mode] }}</h2>
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                    <x-icon name="x" />
                </button>
            </div>

            <div class="space-y-4 px-5 pt-2">
                @if ($mode === 'module')
                    <x-field name="title" label="Title" wire:model="title" maxlength="120" autocomplete="off" hint="Like “Week 1: Cells” or “Chapter 3”." autofocus />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field name="startsOn" label="Starts (optional)" type="date" wire:model="startsOn" />
                        <x-field name="endsOn" label="Ends (optional)" type="date" wire:model="endsOn" />
                    </div>
                @elseif ($mode === 'folder')
                    <x-field name="name" label="Name" wire:model="name" maxlength="120" autocomplete="off" autofocus />
                @elseif ($mode === 'file')
                    <x-field name="name" label="Name" wire:model="name" maxlength="200" autocomplete="off" hint="The ending (like .pdf) stays: it says what kind of file this is." autofocus />
                @elseif ($mode === 'upload')
                    <div class="space-y-3">
                        <label class="drop-zone" x-data="{ over: false }" x-bind:class="over && 'is-over'" x-on:dragenter="over = true" x-on:dragleave="over = false" x-on:drop="over = false">
                            <input type="file" multiple wire:model="uploads" accept="{{ \App\Study\FileTypes::accept() }}" class="drop-zone-input">
                            <x-icon name="upload" class="size-6 text-fg-muted" />
                            <span class="font-semibold">Choose files, or drop them here</span>
                            <span class="text-sm text-fg-muted">PDF, Word, PowerPoint, Excel, OpenDocument, text and images, up to {{ \Illuminate\Support\Number::fileSize($maxUpload) }} each</span>
                        </label>
                        <div x-show="progress !== null" x-cloak class="upload-progress" role="progressbar" aria-label="Uploading" aria-valuemin="0" aria-valuemax="100" x-bind:aria-valuenow="progress">
                            <span x-bind:style="`width: ${progress}%`"></span>
                        </div>
                        <p x-show="failed" x-cloak class="field-error">The upload didn't go through. A file may be bigger than {{ \Illuminate\Support\Number::fileSize($maxUpload) }}; try it on its own.</p>
                        @error('uploads') <p class="field-error">{{ $message }}</p> @enderror
                        @error('uploads.*') <p class="field-error">{{ $message }}</p> @enderror
                        @if ($uploads !== [])
                            <ul class="space-y-1 text-sm" role="list" aria-label="Chosen files">
                                @foreach ($uploads as $upload)
                                    @if ($upload instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                        <li class="flex items-center gap-2" wire:key="upload-{{ $loop->index }}">
                                            <x-icon name="file" class="size-4 shrink-0 text-fg-muted" />
                                            <span class="min-w-0 flex-1 break-words">{{ $upload->getClientOriginalName() }}</span>
                                            <span class="shrink-0 text-fg-muted">{{ \Illuminate\Support\Number::fileSize((int) $upload->getSize()) }}</span>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        @endif
                        @if ($uploadErrors !== [])
                            <x-alert tone="danger" :title="count($uploadErrors) === 1 ? 'This file wasn\'t uploaded' : 'These files weren\'t uploaded'">
                                <ul class="space-y-1" role="list">
                                    @foreach ($uploadErrors as [$failedName, $reason])
                                        <li><span class="font-semibold break-words">{{ $failedName }}</span>: {{ $reason }}</li>
                                    @endforeach
                                </ul>
                            </x-alert>
                        @endif
                    </div>
                @elseif ($mode === 'move')
                    <fieldset class="space-y-1">
                        <legend class="field-label mb-2">Move to</legend>
                        @foreach ($moveOptions as $option)
                            <label @class(['move-option', 'is-disabled' => $option['disabled']]) style="--depth: {{ $option['depth'] }}">
                                <input type="radio" name="destination" value="{{ $option['value'] }}" wire:model="destination" @disabled($option['disabled'])>
                                <x-icon :name="$option['icon']" class="size-4 shrink-0 text-fg-muted" />
                                <span class="min-w-0 break-words">{{ $option['label'] }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                @elseif ($targetType === 'note')
                    <p class="text-fg-muted">The note and every saved version of it are deleted. This can't be undone.</p>
                @elseif ($targetType === 'file')
                    <p class="text-fg-muted">The file is deleted from ViStud. This can't be undone.</p>
                @else
                    <p class="text-fg-muted">This can't be undone. Only an empty {{ $targetType }} can be deleted.</p>
                @endif

                @if ($error)
                    <x-alert tone="danger">{{ $error }}</x-alert>
                @endif
            </div>

            <div class="modal-actions">
                <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                <x-button type="submit" :variant="$mode === 'delete' ? 'danger' : 'primary'" wire:loading.attr="aria-busy" wire:target="save" :busy-label="$mode === 'upload' ? 'Checking…' : 'Saving…'" x-bind:disabled="progress !== null">{{ $submit[$mode] }}</x-button>
            </div>
        </form>
    @endif
</dialog>
