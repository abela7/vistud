{{--
    A workspace's Modules section (App\Livewire\Workspaces\Contents, view
    `modules`). Modules reorder by dragging their handle, or from their menu;
    each opens to show its notes and folders, and remembers on this device
    whether it was open.
--}}
@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $dates = fn ($m) => $m->startsOn || $m->endsOn
        ? trim(($m->startsOn ? Carbon::parse($m->startsOn)->format('j M') : '').' – '.($m->endsOn ? Carbon::parse($m->endsOn)->format('j M Y') : ''), ' –')
        : null;
    $holds = function (array $count) {
        $parts = [];
        foreach (['notes' => 'note', 'files' => 'file', 'folders' => 'folder'] as $key => $word) {
            if (($count[$key] ?? 0) > 0) {
                $parts[] = $count[$key].' '.Str::plural($word, $count[$key]);
            }
        }

        return $parts === [] ? 'Empty' : implode(' · ', $parts);
    };
@endphp
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-fg-muted">{{ count($modules) }} {{ Str::plural('module', count($modules)) }}</p>
        <x-button variant="primary" icon="plus" wire:click="newModule">New module</x-button>
    </div>

    <div role="status" aria-live="polite">
        @if ($notice)
            <x-alert tone="success" :live="false">{{ $notice }}</x-alert>
        @endif
    </div>

    @if ($modules === [])
        <section class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border-strong px-6 py-12 text-center">
            <span class="ws-chip size-14"><x-icon name="layers" class="size-6" /></span>
            <h2 class="text-lg font-semibold">No modules yet</h2>
            <p class="max-w-md text-fg-muted">Split the subject into units, like “Week 1: Cells” or “Chapter 3”, and keep each unit's notes, files and folders together.</p>
        </section>
    @else
        <ol class="space-y-3" wire:sort="sortModules" role="list">
            @foreach ($modules as $i => $module)
                <li wire:key="module-{{ $module->id }}" wire:sort:item="{{ $module->id }}" class="module-card" x-data="{ open: $persist(true).as('vistud.module-open.{{ $module->id }}') }">
                    <div class="flex items-center gap-1 py-2 pr-2 pl-1">
                        <button type="button" class="drag-handle" wire:sort:handle aria-label="Drag to reorder {{ $module->title }}" title="Drag to reorder">
                            <x-icon name="grip-vertical" class="size-5" />
                        </button>
                        <button type="button" class="flex min-w-0 flex-1 items-center gap-2 rounded-md py-1.5 text-left" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-controls="module-body-{{ $module->id }}">
                            <x-icon name="chevron-right" class="size-5 shrink-0 text-fg-muted transition-transform" x-bind:class="open && 'rotate-90'" />
                            <span class="min-w-0">
                                <span class="block font-semibold break-words">{{ $module->title }}</span>
                                <span class="block text-sm text-fg-muted">
                                    {{ implode(' · ', array_filter([$dates($module), $holds($counts[$module->id] ?? [])])) }}
                                </span>
                            </span>
                        </button>
                        @include('livewire.workspaces.partials.row-menu', ['id' => $module->id, 'label' => $module->title, 'items' => [
                            ['Edit', 'pencil', "editModule('{$module->id}')", false],
                            ['New note', 'file-plus', "newNote('module', '{$module->id}')", false],
                            ['Upload files', 'upload', "uploadFiles('module', '{$module->id}')", false],
                            ['New folder', 'folder-plus', "newFolder('module', '{$module->id}')", false],
                            ['Move up', 'arrow-up', "moveModuleBy('{$module->id}', -1)", $i === 0],
                            ['Move down', 'arrow-down', "moveModuleBy('{$module->id}', 1)", $i === count($modules) - 1],
                            ['Delete', 'trash-2', "confirmDelete('module', '{$module->id}')", false],
                        ]])
                    </div>
                    <div id="module-body-{{ $module->id }}" x-show="open" class="border-t border-divider px-3 py-2">
                        @include('livewire.workspaces.partials.place', ['key' => "module:{$module->id}"])
                        @unless (isset($children["module:{$module->id}"]) || isset($notesIn["module:{$module->id}"]) || isset($filesIn["module:{$module->id}"]))
                            <p class="px-2 py-2 text-sm text-fg-muted">Nothing here yet.</p>
                        @endunless
                        <div class="flex flex-wrap gap-1">
                            <x-button variant="ghost" icon="file-plus" wire:click="newNote('module', '{{ $module->id }}')">New note</x-button>
                            <x-button variant="ghost" icon="upload" wire:click="uploadFiles('module', '{{ $module->id }}')">Upload files</x-button>
                            <x-button variant="ghost" icon="folder-plus" wire:click="newFolder('module', '{{ $module->id }}')">New folder</x-button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif

    @include('livewire.workspaces.partials.dialog')
</div>
