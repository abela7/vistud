{{--
    An assignment on a page of its own, or a new one (App\Livewire\Workspaces\AssignmentPage), kept calm and in one
    column: a summary (how long is left, where the student is with it, its kind and module) with its details folded
    behind Edit, the plan, its files and notes (one list, with Add for a new one), and deleting it at the very
    bottom. A new one shows the details form straight away. Files chosen on a new one wait, and go up into its own
    folder once it is created (resources/js/uploader.js).
--}}
@php
    use App\Study\Activities;
    use App\Study\FileTypes;

    $new = $assignment === null;
    $moduleTitle = collect($modules)->firstWhere('id', $moduleId)?->title;
    $moods = ['todo' => 'To do', 'doing' => 'In progress', 'done' => 'Done'];
    $urgency = match (true) {
        $new || $assignment->dueOn === null => 'blue',
        $assignment->status === 'done' => 'green',
        $assignment->overdue() => 'red',
        $assignment->dueAt()->lessThan(now()->addDays(2)) => 'amber',
        default => 'blue',
    };
    $backUrl = route('workspaces.show', [$workspace->id, 'assignments']);
@endphp
<div class="mx-auto max-w-7xl space-y-5" x-data="{ edit: {{ $new ? 'true' : 'false' }}, sure: false }" x-on:details-saved.window="edit = false">
    <x-workspace.section-header :workspace="$workspace" :title="$new ? 'New assignment' : $assignment->title" :back-href="$backUrl" back-to="Assignments"
        :eyebrow="$workspace->name.($moduleTitle ? ' · '.$moduleTitle : '').($new ? '' : ' · '.$assignment->kindLabel())">
        @unless ($new)
            @if ($assignment->dueOn !== null)
                <span @class(['assignment-deadline', 'asg-due', "ws-colour-{$urgency}"])>
                    <x-icon :name="$assignment->overdue() ? 'circle-alert' : ($assignment->status === 'done' ? 'circle-check' : 'hourglass')" class="size-4 shrink-0" />
                    <span class="font-semibold">{{ $assignment->timeLeft() ?? 'Done' }}</span>
                    <span class="text-fg-muted">· {{ $assignment->dueWords() }}</span>
                </span>
            @else
                <span class="asg-due text-fg-muted">No deadline yet</span>
            @endif
            @if ($health && $health['state'] !== 'on_track')
                <x-workspace.plan-health :health="$health" />
            @endif
            <div class="segmented segmented-sm" role="group" aria-label="Where you are">
                @foreach ($moods as $key => $word)
                    <button type="button" @class(['segmented-option', 'is-current' => $status === $key]) wire:click="$set('status', '{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}">{{ $word }}</button>
                @endforeach
            </div>
            <button type="button" class="btn btn-ghost btn-sm" x-on:click="edit = ! edit" x-bind:aria-expanded="edit.toString()" aria-controls="assignment-details"><x-icon name="pencil" class="size-4" />Edit details</button>
        @endunless
    </x-workspace.section-header>

    <form id="assignment-details" class="question-panel asg-details" novalidate aria-label="{{ $new ? 'New assignment' : 'Details' }}" @unless ($new) x-show="edit" x-cloak @endunless
        x-on:submit.prevent="if (await $wire.save()) $dispatch('assignment-created')">
        <div class="field asg-details-name">
            <label for="assignment-title" class="field-label">Name</label>
            <input id="assignment-title" type="text" class="input mt-2" wire:model="title" maxlength="{{ Activities::MAX_TITLE }}" autocomplete="off"
                placeholder="Like “Coursework 1: ER diagram” or “Lab report 3”" @if ($new) autofocus @endif>
            @error('title') <p class="field-error mt-2">{{ $message }}</p> @enderror
        </div>

        <div class="asg-details-row">
            <div class="field">
                <label for="assignment-kind" class="field-label">Kind</label>
                <select id="assignment-kind" class="input mt-2" wire:model="kind">
                    @foreach (Activities::KINDS as $value => $word)
                        <option value="{{ $value }}">{{ $word }}</option>
                    @endforeach
                </select>
                @error('kind') <p class="field-error mt-2">{{ $message }}</p> @enderror
            </div>
            <div class="field">
                <label for="assignment-module" class="field-label">Module</label>
                <select id="assignment-module" class="input mt-2" wire:model="moduleId">
                    <option value="">Not in a module</option>
                    @foreach ($modules as $option)
                        <option value="{{ $option->id }}">{{ $option->title }}</option>
                    @endforeach
                </select>
                @error('moduleId') <p class="field-error mt-2">{{ $message }}</p> @enderror
            </div>
        </div>

        <fieldset class="min-w-0">
            <legend class="field-label">Deadline</legend>
            <div class="mt-2 asg-details-row">
                <div class="field">
                    <label for="assignment-due-on" class="sr-only">Day</label>
                    <input id="assignment-due-on" type="date" class="input" wire:model="dueOn">
                    @error('dueOn') <p class="field-error mt-2">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label for="assignment-due-time" class="sr-only">Time (optional)</label>
                    <input id="assignment-due-time" type="time" class="input" wire:model="dueTime">
                    @error('dueTime') <p class="field-error mt-2">{{ $message }}</p> @enderror
                </div>
            </div>
        </fieldset>

        <div class="asg-details-actions">
            @if ($new)
                <a href="{{ $backUrl }}" class="btn btn-secondary">Cancel</a>
            @else
                <button type="button" class="btn btn-ghost" x-on:click="edit = false">Close</button>
            @endif
            <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" :busy-label="$new ? 'Creating…' : 'Saving…'">{{ $new ? 'Create' : 'Save' }}</x-button>
        </div>
    </form>

    @unless ($new)
        <livewire:workspaces.assignment-plan :workspace-id="$workspace->id" :activity-id="$assignment->id" :key="'plan-'.$assignment->id" />
    @endunless

    {{-- Its files and notes: one list, with Add for a new one. Files dropped on it go up into its folder (resources/js/uploader.js). --}}
    @php
        $kept = count($files) + count($notes) + count($subfolders);
        $choices = [['Upload files', 'file-up', "\$dispatch('assignment-files')"]];
        if (! $new) {
            $choices[] = ['Write a note', 'notebook-pen', null, 'writeNote'];
            $choices[] = ['New folder', 'folder-plus', "adding = 'folder'; \$nextTick(() => document.getElementById('assignment-folder-name')?.focus())"];
        }
    @endphp
    <section class="question-panel asg-files space-y-4" aria-labelledby="assignment-files-heading" x-data="{ adding: null, over: false }" x-bind:class="{ 'is-over': over }"
        x-on:dragover.prevent="over = true" x-on:dragleave.self="over = false"
        x-on:drop.prevent="over = false; Alpine.$data($el.querySelector('[data-assignment-upload]')).drop($event)">
        <div class="panel-head">
            <span class="item-icon" aria-hidden="true"><x-icon name="paperclip" class="size-5" /></span>
            <div class="panel-head-text">
                <h2 id="assignment-files-heading" class="panel-title">
                    Files and notes
                    @if ($kept > 0)
                        <span class="count-pill">{{ $kept }}</span>
                    @endif
                </h2>
                <p class="panel-hint">The brief, your files, notes and folders for this assignment.</p>
            </div>
            <div class="panel-head-actions">
                @if ($folder)
                    <a href="{{ route('workspaces.folders.show', [$workspace->id, $folder->id]) }}" class="btn btn-secondary btn-sm" title="Open its folder"><x-icon name="folder-open" class="size-4" /><span class="max-sm:sr-only">Its folder</span></a>
                @endif
                @include('livewire.workspaces.partials.add-menu', ['id' => 'assignment-add', 'items' => $choices])
            </div>
        </div>

        @unless ($new)
            <form wire:submit="addFolder" novalidate class="plan-add" x-show="adding === 'folder'" x-cloak x-on:keydown.escape="adding = null" x-on:folder-added.window="adding = null">
                <label for="assignment-folder-name" class="sr-only">Name of the new folder</label>
                <input id="assignment-folder-name" type="text" class="input" wire:model="folderName" maxlength="{{ \App\Study\Folders::MAX_NAME }}" placeholder="Folder name" autocomplete="off">
                <button type="submit" class="btn btn-secondary btn-sm">Add</button>
                <button type="button" class="topbar-button size-9" x-on:click="adding = null" title="Close"><x-icon name="x" class="size-4" /><span class="sr-only">Close</span></button>
            </form>
            @error('folderName') <p class="field-error">{{ $message }}</p> @enderror
        @endunless

        @if ($kept > 0)
            <ul class="item-list" role="list" aria-label="Files and notes of the assignment">
                @foreach ($subfolders as $child)
                    <li wire:key="assignment-folder-{{ $child->id }}" class="item-row">
                        <span class="item-icon ws-colour-amber" aria-hidden="true"><x-icon name="folder" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <a href="{{ route('workspaces.folders.show', [$workspace->id, $child->id]) }}" class="tile-link">{{ $child->name }}</a>
                            <span class="item-meta">Folder</span>
                        </span>
                        <x-icon name="chevron-right" class="size-5 shrink-0 text-fg-subtle" />
                    </li>
                @endforeach
                @foreach ($notes as $note)
                    <li wire:key="assignment-note-{{ $note->id }}" class="item-row">
                        <span class="item-icon" aria-hidden="true"><x-icon name="file-text" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <a href="{{ route('workspaces.notes.show', [$workspace->id, $note->id]) }}" class="tile-link">{{ $note->displayTitle() }}</a>
                            <span class="item-meta">Note · edited {{ \Illuminate\Support\Carbon::parse($note->updatedAt)->diffForHumans() }}</span>
                        </span>
                        @include('livewire.workspaces.partials.row-menu', ['id' => 'assignment-note-'.$note->id, 'label' => $note->displayTitle(), 'items' => [
                            ['Move to trash', 'trash-2', "trashNote('{$note->id}')", false],
                        ]])
                    </li>
                @endforeach
                @foreach ($files as $file)
                    <li wire:key="assignment-file-{{ $file->id }}" class="item-row">
                        <span class="item-icon" aria-hidden="true"><x-icon :name="$file->icon()" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <a href="{{ route('workspaces.files.show', [$workspace->id, $file->id]) }}" class="tile-link">{{ $file->fileName() }}</a>
                            <span class="item-meta">{{ $file->typeLabel() }} · {{ $file->humanSize() }}</span>
                        </span>
                        @include('livewire.workspaces.partials.row-menu', ['id' => 'assignment-file-'.$file->id, 'label' => $file->fileName(), 'items' => [
                            ['Download', 'download', null, false, route('files.content', [$file->id, 'download' => 1])],
                            ['Move to trash', 'trash-2', "trashFile('{$file->id}')", false],
                        ]])
                    </li>
                @endforeach
            </ul>
        @else
            <div class="empty-place empty-place-slim" x-bind:class="{ 'is-over': over }">
                <span class="item-icon" aria-hidden="true"><x-icon name="file-up" class="size-5" /></span>
                <p class="font-medium">Nothing here yet</p>
                <p class="max-w-md text-sm text-fg-muted">Add the brief or your files, or write a note<span class="only-fine-pointer">. You can also drop files here</span>.</p>
                <button type="button" class="btn btn-secondary btn-sm" x-on:click="$dispatch('assignment-files')"><x-icon name="file-up" class="size-4" />Upload files</button>
            </div>
        @endif

        {{-- resources/js/uploader.js: Livewire leaves the list alone. On a new one the files wait for Create. --}}
        <div wire:ignore data-assignment-upload
            x-data="uploader({ url: @js(route('api.v1.files.store')), maxBytes: {{ $maxUpload }}, extensions: @js([...array_keys(FileTypes::TYPES), 'jpeg']), hold: @js($new), placeFor: () => $wire.filesFolder() })"
            x-on:assignment-created.window="start()" x-on:assignment-files.window="$refs.files.click()">
            <input type="file" multiple hidden x-ref="files" x-on:change="choose($event)" accept="{{ FileTypes::accept() }}" data-upload-files>
            <ul class="upload-list" role="list" aria-label="Chosen files" x-show="items.some((item) => held || item.state !== 'done')" x-cloak>
                <template x-for="item in items.filter((item) => held || item.state !== 'done')" :key="item.key">
                    <li class="upload-item" x-bind:data-state="item.state">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium" x-text="item.path"></span>
                            <span class="block text-sm text-fg-muted" x-text="item.state === 'sending' ? `Uploading… ${item.progress}%` : (item.state === 'waiting' && held ? 'Added when you create it' : item.note)"></span>
                        </span>
                        <button type="button" class="topbar-button size-8" x-show="held || item.state === 'refused'" x-on:click="forget(item.key)" x-bind:aria-label="`Take out ${item.path}`">
                            <x-icon name="x" class="size-4" />
                        </button>
                    </li>
                </template>
            </ul>
        </div>
    </section>

    @unless ($new)
        <section class="asg-delete" aria-labelledby="assignment-delete-heading">
            <div class="min-w-0">
                <h2 id="assignment-delete-heading" class="font-semibold">Delete this assignment</h2>
                <p class="text-sm text-fg-muted" x-show="! sure">Its plan goes. Its folder and files stay where they are.</p>
                <p class="text-sm" x-show="sure" x-cloak>Really delete it? This can't be undone.</p>
            </div>
            <div class="asg-delete-actions">
                <button type="button" class="btn btn-secondary btn-sm" x-show="! sure" x-on:click="sure = true"><x-icon name="trash-2" class="size-4" />Delete</button>
                <button type="button" class="btn btn-danger btn-sm" x-show="sure" x-cloak wire:click="delete">Yes, delete it</button>
                <button type="button" class="btn btn-secondary btn-sm" x-show="sure" x-cloak x-on:click="sure = false">Keep it</button>
            </div>
        </section>
    @endunless

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>
</div>
