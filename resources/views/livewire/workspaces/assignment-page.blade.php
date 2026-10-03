{{--
    An assignment on a page of its own, or a new one (App\Livewire\Workspaces\AssignmentPage), kept calm and in one
    column: a summary (how long is left, where the student is with it, its kind and module) with its details folded
    behind Edit, the plan, its files, and deleting it at the very bottom. A new one shows the details form straight
    away. Files chosen on a new one wait, and go up into its own folder once it is created (resources/js/uploader.js).
--}}
@php
    use App\Study\Activities;
    use App\Study\FileTypes;
    use Illuminate\Support\Number;

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
<div class="mx-auto max-w-3xl space-y-5" x-data="{ edit: {{ $new ? 'true' : 'false' }}, sure: false }" x-on:details-saved.window="edit = false">
    <x-workspace.section-header :workspace="$workspace" :title="$new ? 'New assignment' : $assignment->title" :back-href="$backUrl" back-to="Assignments"
        :eyebrow="$workspace->name.($moduleTitle ? ' · '.$moduleTitle : '')" />

    @unless ($new)
        <section class="asg-summary" aria-label="Summary">
            <div class="asg-summary-main">
                @if ($assignment->dueOn !== null)
                    <div @class(['assignment-deadline', 'asg-due', "ws-colour-{$urgency}"])>
                        <p class="asg-left">
                            <x-icon :name="$assignment->overdue() ? 'circle-alert' : ($assignment->status === 'done' ? 'circle-check' : 'hourglass')" class="size-5 shrink-0" />
                            <span>{{ $assignment->timeLeft() ?? 'Done' }}</span>
                        </p>
                        <p class="text-sm text-fg-muted">{{ $assignment->dueWords() }}</p>
                    </div>
                @else
                    <p class="text-sm text-fg-muted">No deadline yet.</p>
                @endif
                @if ($health && $health['state'] !== 'on_track')
                    <x-workspace.plan-health :health="$health" class="mt-1" />
                @endif
                <p class="item-meta">{{ $assignment->kindLabel() }}{{ $moduleTitle ? ' · '.$moduleTitle : '' }}</p>
            </div>
            <div class="asg-summary-actions">
                <div class="segmented segmented-sm" role="group" aria-label="Where you are">
                    @foreach ($moods as $key => $word)
                        <button type="button" @class(['segmented-option', 'is-current' => $status === $key]) wire:click="$set('status', '{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}">{{ $word }}</button>
                    @endforeach
                </div>
                <button type="button" class="btn btn-ghost btn-sm" x-on:click="edit = ! edit" x-bind:aria-expanded="edit.toString()" aria-controls="assignment-details"><x-icon name="pencil" class="size-4" />Edit details</button>
            </div>
        </section>
    @endunless

    <form id="assignment-details" class="question-panel space-y-4" novalidate aria-label="{{ $new ? 'New assignment' : 'Details' }}" @unless ($new) x-show="edit" x-cloak @endunless
        x-on:submit.prevent="if (await $wire.save()) $dispatch('assignment-created')">
        <div class="field">
            <label for="assignment-title" class="field-label">Name</label>
            <input id="assignment-title" type="text" class="input mt-2" wire:model="title" maxlength="{{ Activities::MAX_TITLE }}" autocomplete="off"
                placeholder="Like “Coursework 1: ER diagram” or “Lab report 3”" @if ($new) autofocus @endif>
            @error('title') <p class="field-error mt-2">{{ $message }}</p> @enderror
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
            <div class="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-2">
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

        <div class="flex flex-wrap justify-end gap-3">
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

    <section class="question-panel space-y-3" aria-labelledby="assignment-files-heading">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="assignment-files-heading" class="flex items-center gap-2 font-semibold">
                <x-icon name="paperclip" class="size-4 text-fg-muted" />Files
                @if (count($files) + count($notes) > 0)
                    <span class="count-pill">{{ count($files) + count($notes) }}</span>
                @endif
            </h2>
            @if ($folder)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('workspaces.notes.create', [$workspace->id, 'in' => "folder:{$folder->id}"]) }}" class="btn btn-ghost btn-sm"><x-icon name="notebook-pen" class="size-4" />Write a note</a>
                    <a href="{{ route('workspaces.folders.show', [$workspace->id, $folder->id]) }}" class="btn btn-ghost btn-sm"><x-icon name="folder-open" class="size-4" />Open its folder</a>
                </div>
            @endif
        </div>

        @if ($files !== [] || $notes !== [])
            <ul class="assignment-files" role="list">
                @foreach ($files as $file)
                    <li wire:key="assignment-file-{{ $file->id }}" class="item-row">
                        <span class="item-icon" aria-hidden="true"><x-icon :name="$file->icon()" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <a href="{{ route('workspaces.files.show', [$workspace->id, $file->id]) }}" class="tile-link">{{ $file->fileName() }}</a>
                            <span class="item-meta">{{ $file->typeLabel() }} · {{ $file->humanSize() }}</span>
                        </span>
                        <x-icon name="chevron-right" class="size-5 shrink-0 text-fg-subtle" />
                    </li>
                @endforeach
                @foreach ($notes as $note)
                    <li wire:key="assignment-note-{{ $note->id }}" class="item-row">
                        <span class="item-icon" aria-hidden="true"><x-icon name="file-text" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <a href="{{ route('workspaces.notes.show', [$workspace->id, $note->id]) }}" class="tile-link">{{ $note->displayTitle() }}</a>
                            <span class="item-meta">Note</span>
                        </span>
                        <x-icon name="chevron-right" class="size-5 shrink-0 text-fg-subtle" />
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- resources/js/uploader.js: Livewire leaves the list alone. On a new one the files wait for Create. --}}
        <div wire:ignore class="space-y-3"
            x-data="uploader({ url: @js(route('api.v1.files.store')), maxBytes: {{ $maxUpload }}, extensions: @js([...array_keys(FileTypes::TYPES), 'jpeg']), hold: @js($new), placeFor: () => $wire.filesFolder() })"
            x-on:assignment-created.window="start()">
            <div class="drop-zone drop-zone-slim" x-bind:class="over && 'is-over'" x-on:dragover.prevent="over = true" x-on:dragleave="over = false" x-on:drop.prevent="drop($event)">
                <span class="flex flex-wrap items-center justify-center gap-x-3 gap-y-2">
                    <x-icon name="upload" class="size-5 text-fg-muted" />
                    <span class="font-semibold only-fine-pointer">Drop the brief or your files here</span>
                    <x-button icon="file-up" x-on:click="$refs.files.click()">Choose files</x-button>
                </span>
                <span class="text-sm text-fg-muted">Up to {{ Number::fileSize($maxUpload) }} each<span x-show="held"> · added when you create it</span></span>
                <input type="file" multiple hidden x-ref="files" x-on:change="choose($event)" accept="{{ FileTypes::accept() }}" data-upload-files>
            </div>
            <ul class="upload-list" role="list" aria-label="Chosen files" x-show="items.length > 0" x-cloak>
                <template x-for="item in items" :key="item.key">
                    <li class="upload-item" x-bind:data-state="item.state">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium" x-text="item.path"></span>
                            <span class="block text-sm text-fg-muted" x-text="item.state === 'sending' ? `Uploading… ${item.progress}%` : (item.state === 'waiting' && held ? 'Ready to add' : item.note)"></span>
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
        <div class="asg-delete">
            <button type="button" class="quiet-link" x-show="! sure" x-on:click="sure = true"><x-icon name="trash-2" class="size-4" />Delete this assignment</button>
            <div class="flex flex-wrap items-center gap-2" x-show="sure" x-cloak>
                <p class="text-sm text-fg-muted">Delete it? Its folder and files stay where they are.</p>
                <button type="button" class="btn btn-danger btn-sm" wire:click="delete">Delete it</button>
                <button type="button" class="btn btn-ghost btn-sm" x-on:click="sure = false">Keep it</button>
            </div>
        </div>
    @endunless

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>
</div>
