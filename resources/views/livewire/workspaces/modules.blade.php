{{--
    A workspace's Modules section (App\Livewire\Workspaces\Modules). Modules
    reorder by dragging their handle, or from their menu; each opens to show
    its folders, and remembers on this device whether it was open.
--}}
@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $dates = fn ($m) => $m->startsOn || $m->endsOn
        ? trim(($m->startsOn ? Carbon::parse($m->startsOn)->format('j M') : '').' – '.($m->endsOn ? Carbon::parse($m->endsOn)->format('j M Y') : ''), ' –')
        : null;
    $headings = [
        'module' => $creating ? 'New module' : 'Edit module',
        'folder' => $creating ? 'New folder'.($target ? ' in '.$target : '') : 'Rename folder',
        'move' => 'Move “'.$target.'”',
        'delete' => 'Delete “'.$target.'”?',
    ];
    $submit = ['module' => $creating ? 'Add module' : 'Save', 'folder' => $creating ? 'Add folder' : 'Rename', 'move' => 'Move', 'delete' => 'Delete'];
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
            <p class="max-w-md text-fg-muted">Split the subject into units, like “Week 1: Cells” or “Chapter 3”, and keep each unit's folders, notes and files together.</p>
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
                                    {{ implode(' · ', array_filter([$dates($module), ($counts[$module->id] ?? 0).' '.Str::plural('folder', $counts[$module->id] ?? 0)])) }}
                                </span>
                            </span>
                        </button>
                        @include('livewire.workspaces.partials.row-menu', ['id' => $module->id, 'label' => $module->title, 'items' => [
                            ['Edit', 'pencil', "editModule('{$module->id}')", false],
                            ['New folder', 'folder-plus', "newFolder('module', '{$module->id}')", false],
                            ['Move up', 'arrow-up', "moveModuleBy('{$module->id}', -1)", $i === 0],
                            ['Move down', 'arrow-down', "moveModuleBy('{$module->id}', 1)", $i === count($modules) - 1],
                            ['Delete', 'trash-2', "confirmDelete('module', '{$module->id}')", false],
                        ]])
                    </div>
                    <div id="module-body-{{ $module->id }}" x-show="open" class="border-t border-divider px-3 py-2">
                        @include('livewire.workspaces.partials.folder-list', ['key' => "module:{$module->id}"])
                        @unless (isset($children["module:{$module->id}"]))
                            <p class="px-2 py-2 text-sm text-fg-muted">Nothing here yet.</p>
                        @endunless
                        <x-button variant="ghost" icon="folder-plus" wire:click="newFolder('module', '{{ $module->id }}')">New folder</x-button>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif

    <dialog id="structure-dialog" class="modal" aria-labelledby="structure-dialog-title"
        wire:ignore.self
        x-data
        x-on:structure-dialog-open.window="$el.open || $el.showModal()"
        x-on:structure-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.mode && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($mode)
            <form wire:submit="save" novalidate class="modal-panel" wire:key="dialog-{{ $mode }}-{{ $targetId }}-{{ $creating ? 'new' : 'edit' }}">
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
                    @elseif ($mode === 'move')
                        <fieldset class="space-y-1">
                            <legend class="field-label mb-2">Move to</legend>
                            @foreach ($moveOptions as $option)
                                <label @class(['move-option', 'is-disabled' => $option['disabled']]) style="--depth: {{ $option['depth'] }}">
                                    <input type="radio" name="destination" value="{{ $option['value'] }}" wire:model="destination" @disabled($option['disabled'])>
                                    <x-icon :name="$option['depth'] === 0 ? 'layers' : 'folder'" class="size-4 shrink-0 text-fg-muted" />
                                    <span class="min-w-0 break-words">{{ $option['label'] }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @else
                        <p class="text-fg-muted">This can't be undone. Only an empty {{ $targetType }} can be deleted.</p>
                    @endif

                    @if ($error)
                        <x-alert tone="danger">{{ $error }}</x-alert>
                    @endif
                </div>

                <div class="modal-actions">
                    <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                    <x-button type="submit" :variant="$mode === 'delete' ? 'danger' : 'primary'" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">{{ $submit[$mode] }}</x-button>
                </div>
            </form>
        @endif
    </dialog>
</div>
