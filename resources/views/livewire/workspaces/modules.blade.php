{{--
    A workspace's Modules section (App\Livewire\Workspaces\Contents, view
    `modules`): each module a card to open, with what it holds and how many
    of its topics are understood. Cards reorder by dragging, or from their
    menu.
--}}
@php
    use App\Appearance\Theme;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $dates = fn ($m) => $m->startsOn || $m->endsOn
        ? trim(($m->startsOn ? Carbon::parse($m->startsOn)->format('j M') : '').' – '.($m->endsOn ? Carbon::parse($m->endsOn)->format('j M') : ''), ' –')
        : null;
    $holds = function (array $count) {
        $parts = [];
        foreach (['notes' => 'note', 'files' => 'file', 'links' => 'link', 'folders' => 'folder'] as $key => $word) {
            if (($count[$key] ?? 0) > 0) {
                $parts[] = $count[$key].' '.Str::plural($word, $count[$key]);
            }
        }

        return $parts === [] ? 'Empty' : implode(' · ', $parts);
    };
@endphp
<div class="space-y-4">
    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" />
    </div>

    <ol class="module-grid" wire:sort="sortModules" role="list" aria-label="Modules">
        @foreach ($modules as $i => $module)
            @php
                $colour = Theme::CATEGORIES[crc32($module->id) % count(Theme::CATEGORIES)];
                $topics = $progress[$module->id] ?? null;
            @endphp
            <li wire:key="module-{{ $module->id }}" wire:sort:item="{{ $module->id }}" class="module-tile ws-colour-{{ $colour }}">
                <div class="flex items-start justify-between gap-2">
                    <span class="place-badge" aria-hidden="true">{{ $i + 1 }}</span>
                    @include('livewire.workspaces.partials.row-menu', ['id' => $module->id, 'label' => $module->title, 'items' => [
                        ['Edit', 'pencil', "editModule('{$module->id}')", false],
                        ['Instructions for the AI', 'message-square-text', "editInstructions('{$module->id}')", false],
                        ['Move earlier', 'arrow-up', "moveModuleBy('{$module->id}', -1)", $i === 0],
                        ['Move later', 'arrow-down', "moveModuleBy('{$module->id}', 1)", $i === count($modules) - 1],
                        ['Delete', 'trash-2', "confirmDelete('module', '{$module->id}')", false],
                    ]])
                </div>
                <div class="min-w-0 space-y-1">
                    <h2 class="text-lg font-semibold break-words"><a href="{{ route('workspaces.modules.show', [$workspaceId, $module->id]) }}" class="tile-link">{{ $module->title }}</a></h2>
                    <p class="item-meta">{{ implode(' · ', array_filter([$dates($module), $holds($counts[$module->id] ?? [])])) }}</p>
                </div>
                @if ($topics)
                    <div class="space-y-1">
                        <div class="meter" role="progressbar" aria-label="Topics understood in {{ $module->title }}" aria-valuemin="0" aria-valuemax="{{ $topics['total'] }}" aria-valuenow="{{ $topics['done'] }}">
                            <span style="width: {{ round($topics['done'] / $topics['total'] * 100) }}%"></span>
                        </div>
                        <p class="item-meta">{{ $topics['done'] }} of {{ $topics['total'] }} topics understood</p>
                    </div>
                @endif
            </li>
        @endforeach
        <li class="module-tile is-new" wire:key="module-new">
            <button type="button" class="new-tile" wire:click="newModule">
                <x-icon name="plus" class="size-6" />
                <span class="font-semibold">New module</span>
            </button>
        </li>
    </ol>

    @include('livewire.workspaces.partials.dialog')
</div>
