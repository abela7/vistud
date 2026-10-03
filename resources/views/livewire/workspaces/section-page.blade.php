{{--
    A section on a page of its own (App\Livewire\Workspaces\SectionPage): how far it has got and what it is worth,
    then its tasks, its files and its notes, one tab at a time. Its notes, files and folders are kept in the
    section's folder, inside the assignment's folder.
--}}
@php
    use Illuminate\Support\Carbon;

    $pct = fn (float $value) => rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    $back = route('workspaces.assignments.show', [$workspaceId, $activityId]);
    $counts = ['tasks' => count($tasks), 'files' => count($files) + count($folders), 'notes' => count($notes)];
    $state = $plan->stateOf($section);
@endphp
<div class="mx-auto max-w-7xl space-y-5" x-data="{ sure: false, adding: null, over: false }"
    x-on:dragover.prevent="over = true" x-on:dragleave.self="over = false"
    x-on:drop.prevent="over = false; Alpine.$data($el.querySelector('[data-section-upload]')).drop($event); $wire.showTab('files')">
    <x-workspace.section-header :workspace="$workspace" :title="$section->title" :back-href="$back" :back-to="$assignment->title" :eyebrow="$workspace->name.' · '.$assignment->title">
        <span @class(['plan-weight-chip', 'is-unset' => $section->weight === null]) title="Its weight in the assignment">{{ $section->weight === null ? 'No weight' : $section->weight.'%' }}</span>
        <a href="{{ route('workspaces.assignments.sections.edit', [$workspaceId, $activityId, $sectionId]) }}" class="btn btn-ghost btn-sm"><x-icon name="pencil" class="size-4" />Edit</a>
        @if ($section->folderId !== null)
            <a href="{{ route('workspaces.folders.show', [$workspaceId, $section->folderId]) }}" class="btn btn-ghost btn-sm"><x-icon name="folder-open" class="size-4" />Its folder</a>
        @endif
        <button type="button" class="btn btn-ghost btn-sm" x-on:click="sure = true"><x-icon name="trash-2" class="size-4" />Delete</button>
    </x-workspace.section-header>

    <div class="plan-confirm" role="alert" x-show="sure" x-cloak>
        <p class="font-medium">Delete this section and its tasks? Its files and notes stay in the assignment's folder.</p>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-danger btn-sm" wire:click="deleteSection">Delete it</button>
            <button type="button" class="btn btn-ghost btn-sm" x-on:click="sure = false">Keep it</button>
        </div>
    </div>

    <section class="question-panel plan-progress" aria-label="How far it is">
        <div class="plan-progress-row">
            <p class="plan-percent tabular-nums">{{ $standing['percent'] }}%</p>
            <div class="meter plan-progress-meter" role="progressbar" aria-label="Progress of {{ $section->title }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $standing['percent'] }}">
                <span style="width: {{ $standing['percent'] }}%"></span>
            </div>
            @if ($standing['total'] > 0)
                <p class="text-sm text-fg-muted tabular-nums">{{ $standing['done'] }} of {{ $standing['total'] }} tasks done</p>
            @else
                {{-- With no tasks, the section itself is ticked. --}}
                <button type="button" class="btn btn-secondary btn-sm" wire:click="setState('{{ $sectionId }}', '{{ $state === 'done' ? 'todo' : 'done' }}')" aria-pressed="{{ $state === 'done' ? 'true' : 'false' }}">
                    <x-icon :name="$state === 'done' ? 'circle-check' : 'circle'" class="size-4" />{{ $state === 'done' ? 'Done' : 'Mark done' }}
                </button>
            @endif
        </div>
        <div class="plan-facts">
            <span class="plan-fact"><x-icon name="scale" class="size-4" />{{ $pct($standing['earned']) }} of {{ $pct($standing['share']) }}% of the assignment earned</span>
            @if ($section->when())
                <span @class(['plan-fact', 'ws-colour-red' => $plan->overdue($section, $today)])><x-icon name="calendar" class="size-4" />{{ $plan->overdue($section, $today) ? 'Overdue · ' : '' }}{{ $section->when() }}</span>
            @endif
            @if ($state === 'stuck')
                <span class="plan-fact ws-colour-red"><x-icon name="circle-alert" class="size-4" />Something in it is stuck</span>
            @endif
        </div>
        @if ($section->notes)
            <p class="plan-notes">{{ $section->notes }}</p>
        @endif
    </section>

    <div class="segmented" role="group" aria-label="Show">
        @foreach (\App\Livewire\Workspaces\SectionPage::TABS as $key => $word)
            <button type="button" @class(['segmented-option', 'is-current' => $tab === $key]) wire:click="showTab('{{ $key }}')" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}">
                {{ $word }} <span class="tab-count">{{ $counts[$key] }}</span>
            </button>
        @endforeach
    </div>

    @if ($tab === 'tasks')
        <section class="question-panel section-panel space-y-3" x-bind:class="{ 'is-over': over }" aria-labelledby="tasks-heading">
            <h2 id="tasks-heading" class="sr-only">Tasks</h2>
            <form wire:submit="addTask" novalidate class="plan-add">
                <label for="plan-step-{{ $sectionId }}" class="sr-only">Add a task to {{ $section->title }}</label>
                <input id="plan-step-{{ $sectionId }}" type="text" class="input" wire:model="stepText.{{ $sectionId }}" maxlength="{{ \App\Study\Plans::MAX_TITLE }}" placeholder="Add a task" autocomplete="off">
                <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="plus" class="size-4" />Add</button>
            </form>
            @error('stepText.'.$sectionId) <p class="field-error">{{ $message }}</p> @enderror

            @if ($tasks === [])
                <p class="text-sm text-fg-muted">No tasks yet. Break the section into tasks, and a task into sub-tasks with its ⋯ menu.</p>
            @else
                <ul class="plan-steps" role="list" aria-label="Tasks of {{ $section->title }}">
                    @foreach ($tasks as $step)
                        @include('livewire.workspaces.partials.plan-step', ['step' => $step, 'first' => $loop->first, 'last' => $loop->last, 'depth' => 1])
                    @endforeach
                </ul>
            @endif
        </section>
    @elseif ($tab === 'files')
        <section class="section-panel space-y-3" x-bind:class="{ 'is-over': over }" aria-labelledby="files-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="files-heading" class="font-semibold">Files</h2>
                @include('livewire.workspaces.partials.add-menu', ['id' => 'section-add-files', 'items' => [
                    ['Upload files', 'file-up', "\$dispatch('section-files')"],
                    ['New folder', 'folder-plus', "adding = 'folder'; \$nextTick(() => document.getElementById('section-folder-name')?.focus())"],
                ]])
            </div>
            <form wire:submit="addFolder" novalidate class="plan-add" x-show="adding === 'folder'" x-cloak x-on:keydown.escape="adding = null" x-on:folder-added.window="adding = null">
                <label for="section-folder-name" class="sr-only">Name of the new folder</label>
                <input id="section-folder-name" type="text" class="input" wire:model="folderName" maxlength="{{ \App\Study\Folders::MAX_NAME }}" placeholder="Folder name" autocomplete="off">
                <button type="submit" class="btn btn-secondary btn-sm">Add</button>
                <button type="button" class="topbar-button size-9" x-on:click="adding = null" title="Close"><x-icon name="x" class="size-4" /><span class="sr-only">Close</span></button>
            </form>
            @error('folderName') <p class="field-error">{{ $message }}</p> @enderror

            @if ($files === [] && $folders === [])
                <p class="text-sm text-fg-muted">No files yet. Add them with Add, or drop them anywhere on this page.</p>
            @else
                <ul class="item-list" role="list" aria-label="Files of {{ $section->title }}">
                    @foreach ($folders as $folder)
                        <li wire:key="sec-folder-{{ $folder->id }}" class="item-row">
                            <span class="item-icon ws-colour-amber" aria-hidden="true"><x-icon name="folder" class="size-5" /></span>
                            <span class="min-w-0 flex-1">
                                <a href="{{ route('workspaces.folders.show', [$workspaceId, $folder->id]) }}" class="tile-link">{{ $folder->name }}</a>
                                <span class="item-meta">Folder</span>
                            </span>
                            <x-icon name="chevron-right" class="size-5 shrink-0 text-fg-subtle" />
                        </li>
                    @endforeach
                    @foreach ($files as $file)
                        <li wire:key="sec-file-{{ $file->id }}" class="item-row">
                            <span class="item-icon" aria-hidden="true"><x-icon :name="$file->icon()" class="size-5" /></span>
                            <span class="min-w-0 flex-1">
                                <a href="{{ route('workspaces.files.show', [$workspaceId, $file->id]) }}" class="tile-link">{{ $file->fileName() }}</a>
                                <span class="item-meta">{{ $file->typeLabel() }} · {{ $file->humanSize() }}</span>
                            </span>
                            @include('livewire.workspaces.partials.row-menu', ['id' => 'sec-file-'.$file->id, 'label' => $file->fileName(), 'items' => [
                                ['Download', 'download', null, false, route('files.content', [$file->id, 'download' => 1])],
                                ['Move to trash', 'trash-2', "trashFile('{$file->id}')", false],
                            ]])
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @else
        <section class="section-panel space-y-3" x-bind:class="{ 'is-over': over }" aria-labelledby="notes-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="notes-heading" class="font-semibold">Notes</h2>
                <button type="button" class="btn btn-secondary btn-sm" wire:click="writeNote"><x-icon name="notebook-pen" class="size-4" />New note</button>
            </div>
            @if ($notes === [])
                <p class="text-sm text-fg-muted">No notes yet. A note written here is kept with this section.</p>
            @else
                <ul class="item-list" role="list" aria-label="Notes of {{ $section->title }}">
                    @foreach ($notes as $note)
                        <li wire:key="sec-note-{{ $note->id }}" class="item-row">
                            <span class="item-icon" aria-hidden="true"><x-icon name="file-text" class="size-5" /></span>
                            <span class="min-w-0 flex-1">
                                <a href="{{ route('workspaces.notes.show', [$workspaceId, $note->id]) }}" class="tile-link">{{ $note->displayTitle() }}</a>
                                <span class="item-meta">Edited {{ Carbon::parse($note->updatedAt)->diffForHumans() }}</span>
                            </span>
                            @include('livewire.workspaces.partials.row-menu', ['id' => 'sec-note-'.$note->id, 'label' => $note->displayTitle(), 'items' => [
                                ['Move to trash', 'trash-2', "trashNote('{$note->id}')", false],
                            ]])
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

        {{-- resources/js/uploader.js: the files go up into the section's folder, made when the first one goes. Outside the tabs, so a drop works from any of them. --}}
        <div wire:ignore data-section-upload
            x-data="uploader({ url: @js(route('api.v1.files.store')), maxBytes: {{ $maxUpload }}, extensions: @js($extensions), placeFor: () => $wire.sectionFolder() })"
            x-on:section-files.window="$refs.files.click()">
            <input type="file" multiple hidden x-ref="files" x-on:change="choose($event)" accept="{{ \App\Study\FileTypes::accept() }}">
            <ul class="upload-list" role="list" aria-label="Files going up" x-show="items.some((item) => item.state !== 'done')" x-cloak>
                <template x-for="item in items.filter((item) => item.state !== 'done')" :key="item.key">
                    <li class="upload-item" x-bind:data-state="item.state">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium" x-text="item.path"></span>
                            <span class="block text-sm text-fg-muted" x-text="item.state === 'sending' ? `Uploading… ${item.progress}%` : item.note"></span>
                        </span>
                    </li>
                </template>
            </ul>
        </div>

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>
</div>
