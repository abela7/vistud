{{--
    A module's classes (App\Livewire\Workspaces\ModuleClasses): the list, and
    one tap on a kind adds today's class. Each class opens on its own page.
--}}
<div class="space-y-6">
    @if ($classes !== [])
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-4">
            <p class="text-sm font-medium text-fg-muted">{{ count($classes) }} {{ \Illuminate\Support\Str::plural('class', count($classes)) }}</p>
            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Add a class held today">
                @foreach ($kinds as $value => $label)
                    <x-button variant="secondary" icon="plus" wire:click="add('{{ $value }}')" wire:loading.attr="disabled" wire:target="add">{{ $label }}</x-button>
                @endforeach
            </div>
        </div>
        <ul class="item-list" role="list" aria-label="Classes">
            @foreach ($classes as $class)
                <li class="item-row" wire:key="class-{{ $class->id }}">
                    <span class="item-icon" aria-hidden="true">
                        <x-icon name="presentation" class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-0.5">
                        <a href="{{ route('workspaces.modules.classes.show', [$workspaceId, $moduleId, $class->id]) }}" class="tile-link font-medium">{{ $class->title }}</a>
                        <p class="item-meta">{{ $class->line() }} · {{ $class->statusLabel() }}</p>
                    </div>
                    <div class="shrink-0">
                        <a href="{{ route('workspaces.modules.classes.show', [$workspaceId, $moduleId, $class->id]) }}" class="btn btn-ghost btn-sm" aria-label="Open {{ $class->title }}">
                            <x-icon name="chevron-right" class="size-4" />
                        </a>
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <div class="card space-y-3 p-8 text-center">
            <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-surface-sunken text-fg-muted" aria-hidden="true">
                <x-icon name="presentation" class="size-6" />
            </span>
            <div class="space-y-1">
                <h2 class="text-base font-semibold">No classes yet</h2>
                <p class="mx-auto max-w-md text-sm text-fg-muted">Tap the kind of class you are in. Today's class is made and opens, ready to record.</p>
            </div>
            <div class="flex flex-wrap items-center justify-center gap-2 pt-2" role="group" aria-label="Add a class held today">
                @foreach ($kinds as $value => $label)
                    <x-button variant="primary" icon="plus" wire:click="add('{{ $value }}')" wire:loading.attr="disabled" wire:target="add">{{ $label }}</x-button>
                @endforeach
            </div>
        </div>
    @endif
</div>
