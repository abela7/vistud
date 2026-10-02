{{--
    An assignment on a page of its own, or a new one (App\Livewire\Workspaces\AssignmentPage). On the left its
    details (name, kind, deadline, module) and its files; on the right how long is left, where the student is
    with it, and deleting it. On a phone the right column comes after the files. Files chosen on a new one wait,
    and go up into its own folder once it is created (resources/js/uploader.js).
--}}
@php
    use App\Study\Activities;
    use App\Study\FileTypes;
    use Illuminate\Support\Number;

    $new = $assignment === null;
    $moduleTitle = collect($modules)->firstWhere('id', $moduleId)?->title;
    $look = ['todo' => ['To do', 'circle', 'blue'], 'doing' => ['In progress', 'clock', 'amber'], 'done' => ['Done', 'circle-check', 'green']];
    $urgency = match (true) {
        $new || $assignment->dueOn === null => 'blue',
        $assignment->status === 'done' => 'green',
        $assignment->overdue() => 'red',
        $assignment->dueAt()->lessThan(now()->addDays(2)) => 'amber',
        default => 'blue',
    };
    $backUrl = route('workspaces.show', [$workspace->id, 'assignments']);
@endphp
<div class="space-y-5">
    <x-workspace.section-header :workspace="$workspace" :title="$new ? 'New assignment' : $assignment->title" :back-href="$backUrl" back-to="Assignments"
        :eyebrow="$workspace->name.($moduleTitle ? ' · '.$moduleTitle : '')">
        @unless ($new)
            <span @class(['question-status', "ws-colour-{$look[$assignment->status][2]}"])>
                <x-icon :name="$look[$assignment->status][1]" class="size-4" />{{ $look[$assignment->status][0] }}
            </span>
        @endunless
    </x-workspace.section-header>

    <div class="question-layout" x-data="{ sure: false }">
        <div class="question-main space-y-5">
            <form class="question-panel space-y-4" novalidate aria-label="{{ $new ? 'New assignment' : 'Details' }}"
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
                            <input id="assignment-due-on" type="date" class="input" wire:model="dueOn" aria-describedby="assignment-due-hint">
                            @error('dueOn') <p class="field-error mt-2">{{ $message }}</p> @enderror
                        </div>
                        <div class="field">
                            <label for="assignment-due-time" class="sr-only">Time (optional)</label>
                            <input id="assignment-due-time" type="time" class="input" wire:model="dueTime" aria-describedby="assignment-due-hint">
                            @error('dueTime') <p class="field-error mt-2">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <p id="assignment-due-hint" class="field-hint mt-2">The day it's due, and the time if there is one (otherwise the end of that day).</p>
                </fieldset>

                <div class="flex flex-wrap justify-end gap-3">
                    @if ($new)
                        <a href="{{ $backUrl }}" class="btn btn-secondary">Cancel</a>
                    @endif
                    <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" :busy-label="$new ? 'Creating…' : 'Saving…'">{{ $new ? 'Create' : 'Save' }}</x-button>
                </div>
            </form>

            @unless ($new)
                <livewire:workspaces.assignment-plan :workspace-id="$workspace->id" :activity-id="$assignment->id" :key="'plan-'.$assignment->id" />
            @endunless

            <section class="question-panel space-y-4" aria-labelledby="assignment-files-heading">
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
                    <div class="drop-zone" x-bind:class="over && 'is-over'" x-on:dragover.prevent="over = true" x-on:dragleave="over = false" x-on:drop.prevent="drop($event)">
                        <x-icon name="upload" class="size-6 text-fg-muted" />
                        <span class="font-semibold only-fine-pointer">Drop the brief, the rubric or your drafts here</span>
                        <span class="font-semibold only-touch">Add the brief, the rubric or your drafts</span>
                        <span class="text-sm text-fg-muted">
                            PDF, Word, PowerPoint, Excel, text and images, up to {{ Number::fileSize($maxUpload) }} each.
                            <span x-show="held">They're added when you create it.</span>
                        </span>
                        <div class="mt-2 flex flex-wrap justify-center gap-2">
                            <x-button icon="file-up" x-on:click="$refs.files.click()">Choose files</x-button>
                        </div>
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
        </div>

        <aside class="question-options space-y-4" aria-label="Deadline and progress">
            @unless ($new)
                <section @class(['question-panel', 'assignment-deadline', "ws-colour-{$urgency}"]) aria-labelledby="assignment-deadline-label">
                    <p id="assignment-deadline-label" class="field-label">Deadline</p>
                    @if ($assignment->dueOn !== null)
                        <p class="assignment-left">
                            <x-icon :name="$assignment->overdue() ? 'circle-alert' : ($assignment->status === 'done' ? 'circle-check' : 'hourglass')" class="size-5 shrink-0" />
                            <span>{{ $assignment->timeLeft() ?? 'Done' }}</span>
                        </p>
                        <p class="text-sm text-fg-muted">{{ $assignment->dueWords() }}</p>
                        @if ($progress->total > 0 && $assignment->status !== 'done')
                            <div class="meter mt-3" role="progressbar" aria-label="Plan progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress->percent }}"><span style="width: {{ $progress->percent }}%"></span></div>
                            <p class="mt-1 text-sm text-fg-muted">{{ $progress->percent }}% of the plan{{ $pace ? ' · '.$pace['words'] : '' }}</p>
                        @endif
                        <x-workspace.plan-health :health="$health" class="mt-3" />
                    @else
                        <p class="mt-1 text-sm text-fg-muted">No deadline yet. Add its day on the left.</p>
                    @endif
                </section>

                <section class="question-panel">
                    <fieldset class="min-w-0">
                        <legend class="field-label mb-2">Where you are</legend>
                        <div class="status-choices">
                            @foreach (Activities::STATUSES as $key)
                                <label @class(['status-choice', "ws-colour-{$look[$key][2]}"])>
                                    <input type="radio" name="assignment-status" value="{{ $key }}" wire:model.live="status">
                                    <x-icon :name="$look[$key][1]" class="size-5" />
                                    <span>{{ $look[$key][0] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </section>

                <section class="question-panel question-danger" aria-labelledby="assignment-delete-label">
                    <h2 id="assignment-delete-label" class="field-label">Delete this assignment</h2>
                    <p class="field-hint mt-1">It leaves your list. Its folder and files stay where they are.</p>
                    <div class="mt-3">
                        <button type="button" class="btn btn-secondary" x-show="! sure" x-on:click="sure = true"><x-icon name="trash-2" class="size-4" />Delete</button>
                        <div class="flex flex-wrap gap-2" x-show="sure" x-cloak>
                            <button type="button" class="btn btn-danger" wire:click="delete">Delete it</button>
                            <button type="button" class="btn btn-ghost" x-on:click="sure = false">Keep it</button>
                        </div>
                    </div>
                </section>
            @else
                <section class="question-panel space-y-2">
                    <p class="field-label">Its own folder</p>
                    <p class="text-sm text-fg-muted">An assignment keeps its files in a folder of its own, in its module. Everything you add here is also there, with previews and Take notes.</p>
                </section>
            @endunless
        </aside>
    </div>

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>
</div>
