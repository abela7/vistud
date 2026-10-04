{{--
    A workspace's Modules section (App\Livewire\Workspaces\Contents, view
    `modules`), redesigned after the owner's review (2026-09-29): one row of
    heading and actions (Select, Grid or List, New module), then the modules.
    A card is its colour along the top, its number beside its title, when it
    runs, what it holds, and how many of its topics are understood. Cards
    reorder by dragging, or from their menu; the list shows the same in rows.
--}}
@php
    use App\Appearance\Theme;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $dates = fn ($m) => $m->startsOn || $m->endsOn
        ? trim(($m->startsOn ? Carbon::parse($m->startsOn)->format('j M') : '').' – '.($m->endsOn ? Carbon::parse($m->endsOn)->format('j M') : ''), ' –')
        : null;
    // What a module holds, as small counts with their icons.
    $kinds = ['notes' => ['note', 'file-text'], 'files' => ['file', 'file'], 'links' => ['link', 'link'], 'folders' => ['folder', 'folder']];
@endphp
<div class="space-y-5" x-data="selectable({ layout: $persist('grid').as('vistud.modules.layout') })" :class="{ 'is-selecting': isSelecting, 'is-selecting-container': isSelecting }" x-on:keydown.window="handleKeydown($event)" x-on:selection-clear.window="clearSelection()">
    <x-workspace.section-header :workspace="$workspace" title="Modules" :count="$modules === [] ? null : count($modules)" :count-label="Str::plural('module', count($modules))">
        @if ($modules !== [])
            {{-- While selecting, the selection bar's Done ends it; the focus goes to its Select all. --}}
            <button type="button" class="btn btn-secondary" x-show="!isSelecting" x-on:click="toggleMode(); $nextTick(() => $root.querySelector('.selection-all-box input')?.focus())">
                <x-icon name="list-checks" class="size-4" />
                <span class="max-md:sr-only">Select</span>
            </button>
            <div class="view-switch" role="group" aria-label="View mode">
                <button type="button" x-on:click="layout = 'grid'" aria-label="Grid view" title="Grid" :aria-pressed="layout === 'grid'">
                    <x-icon name="layout-grid" class="size-4" />
                </button>
                <button type="button" x-on:click="layout = 'list'" aria-label="List view" title="List" :aria-pressed="layout === 'list'">
                    <x-icon name="list" class="size-4" />
                </button>
            </div>
        @endif
        <a href="{{ route('workspaces.show', [$workspaceId, 'notes']) }}" class="btn btn-secondary">
            <x-icon name="folder-open" class="size-4" />
            <span class="max-md:sr-only">All notes &amp; files</span>
        </a>
        <a href="{{ route('workspaces.guide', [$workspaceId, 'for' => 'modules']) }}" class="btn btn-secondary">
            <x-icon name="sparkles" class="size-4" />
            <span class="max-md:sr-only">Add with the AI</span>
        </a>
        <button type="button" class="btn btn-primary" wire:click="newModule">
            <x-icon name="plus" class="size-4" />
            <span class="max-sm:sr-only">New module</span>
        </button>
    </x-workspace.section-header>

    {{-- Looking for a note or a file: the words go to All notes & files. --}}
    <form method="get" action="{{ route('workspaces.show', [$workspaceId, 'notes']) }}" role="search" class="search-field">
        <x-icon name="search" class="size-4" />
        <label for="modules-search" class="sr-only">Search notes and files</label>
        <input id="modules-search" type="search" name="q" class="input" placeholder="Search notes and files" autocomplete="off">
    </form>

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" />
    </div>

    <x-selection-bar>
        <x-button variant="danger" size="sm" icon="trash-2" ::disabled="count === 0" title="Delete empty modules" x-on:click="$wire.openBulkDelete(selectedKeys())">Delete</x-button>
    </x-selection-bar>

    @if ($modules === [])
        <div class="empty-place">
            <span class="item-icon" aria-hidden="true"><x-icon name="layers" class="size-5" /></span>
            <p class="font-medium">No modules yet</p>
            <p class="text-sm text-fg-muted">A module is a part of the course, like “Week 1” or “Chapter 3”: its notes, files and topics live in it. The AI reads what your course is about and helps you add them, a few weeks at a time.</p>
            <div class="flex flex-wrap items-center justify-center gap-2">
                <a href="{{ route('workspaces.guide', [$workspaceId, 'for' => 'modules']) }}" class="btn btn-primary"><x-icon name="sparkles" class="size-4" />Add the weeks with the AI</a>
                <x-button icon="plus" wire:click="newModule">Add one myself</x-button>
            </div>
        </div>
    @else
        <ol class="module-grid" :class="{ 'is-list-view': layout === 'list' }" wire:sort="sortModules" role="list" aria-label="Modules">
            @foreach ($modules as $i => $module)
                @php
                    $colour = Theme::CATEGORIES[crc32($module->id) % count(Theme::CATEGORIES)];
                    $topics = $progress[$module->id] ?? null;
                    $count = $counts[$module->id] ?? [];
                    $when = $dates($module);
                @endphp
                <li wire:key="module-{{ $module->id }}" wire:sort:item="{{ $module->id }}" class="module-tile ws-colour-{{ $colour }}"
                    data-select-key="module:{{ $module->id }}"
                    :class="{ 'is-selected': isSelected('module:{{ $module->id }}') }"
                    x-on:click="handleRowClick($event, 'module:{{ $module->id }}')">
                    <x-selection-check key="module:{{ $module->id }}" label="Select {{ $module->title }}" />
                    <span class="module-number" aria-hidden="true">{{ $i + 1 }}</span>

                    <div class="module-main">
                        <h2 class="module-title"><a href="{{ route('workspaces.modules.show', [$workspaceId, $module->id]) }}" class="tile-link">{{ $module->title }}</a></h2>
                        @if ($when)
                            <p class="module-when"><x-icon name="calendar" class="size-3.5 shrink-0" />{{ $when }}</p>
                        @endif
                    </div>

                    <ul class="module-holds" role="list" aria-label="In {{ $module->title }}">
                        @forelse (array_filter($kinds, fn ($kind, $key) => ($count[$key] ?? 0) > 0, ARRAY_FILTER_USE_BOTH) as $key => [$word, $icon])
                            <li class="module-hold"><x-icon :name="$icon" class="size-3.5 shrink-0" />{{ $count[$key] }} {{ Str::plural($word, $count[$key]) }}</li>
                        @empty
                            <li class="module-hold is-empty">Nothing in it yet</li>
                        @endforelse
                    </ul>

                    @if ($topics)
                        <div class="module-progress">
                            <p class="module-progress-label"><span>Topics understood</span><span class="tabular-nums">{{ $topics['done'] }}/{{ $topics['total'] }}@if ($topics['tested'] !== null) · tested {{ $topics['tested'] }} %@endif</span></p>
                            <div class="meter" role="progressbar" aria-label="Topics understood in {{ $module->title }}" aria-valuemin="0" aria-valuemax="{{ $topics['total'] }}" aria-valuenow="{{ $topics['done'] }}">
                                <span style="width: {{ round($topics['done'] / $topics['total'] * 100) }}%"></span>
                            </div>
                        </div>
                    @endif

                    <div class="module-actions">
                        @include('livewire.workspaces.partials.row-menu', ['id' => $module->id, 'label' => $module->title, 'items' => [
                            ['Edit', 'pencil', "editModule('{$module->id}')", false],
                            ['Instructions for the AI', 'message-square-text', "editInstructions('{$module->id}')", false],
                            ['Move earlier', 'arrow-up', "moveModuleBy('{$module->id}', -1)", $i === 0],
                            ['Move later', 'arrow-down', "moveModuleBy('{$module->id}', 1)", $i === count($modules) - 1],
                            ['Delete', 'trash-2', "confirmDelete('module', '{$module->id}')", false],
                        ]])
                    </div>
                </li>
            @endforeach
        </ol>
    @endif

    @include('livewire.workspaces.partials.dialog')
</div>
