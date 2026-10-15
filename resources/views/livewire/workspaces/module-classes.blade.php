{{--
    A module's classes (App\Livewire\Workspaces\ModuleClasses): the list, and
    the form that adds one. Each class opens on its own page.
--}}
@php
    $uid = $this->getId();
@endphp
<div class="space-y-6">
    @if ($adding)
        <form wire:submit="add" class="card space-y-4 p-5">
            <div class="space-y-1">
                <h2 class="text-base font-semibold">New class</h2>
                <p class="text-sm text-fg-muted">Name the class. Recording it happens on the next page.</p>
            </div>
            <div class="space-y-1">
                <label for="class-title-{{ $uid }}" class="field-label">Name</label>
                <input id="class-title-{{ $uid }}" type="text" class="input" wire:model="title" maxlength="200" required autocomplete="off">
                @error('title') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="class-kind-{{ $uid }}" class="field-label">Kind</label>
                    <select id="class-kind-{{ $uid }}" class="input" wire:model="kind">
                        @foreach ($kinds as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('kind') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-1">
                    <label for="class-day-{{ $uid }}" class="field-label">Day</label>
                    <input id="class-day-{{ $uid }}" type="date" class="input" wire:model="heldOn" required>
                    @error('held_on') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="space-y-1">
                <label for="class-teacher-{{ $uid }}" class="field-label">Teacher <span class="font-normal text-fg-muted">(optional)</span></label>
                <input id="class-teacher-{{ $uid }}" type="text" class="input" wire:model="teacher" maxlength="120" autocomplete="off">
                @error('teacher') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-button type="submit" variant="primary" busy-label="Adding…">Add class</x-button>
                <x-button type="button" variant="ghost" wire:click="cancel">Cancel</x-button>
            </div>
        </form>
    @endif

    @if ($classes !== [])
        @unless ($adding)
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-4">
                <p class="text-sm font-medium text-fg-muted">{{ count($classes) }} {{ \Illuminate\Support\Str::plural('class', count($classes)) }}</p>
                <x-button variant="primary" icon="plus" wire:click="start">Add a class</x-button>
            </div>
        @endunless
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
    @elseif (! $adding)
        <div class="card space-y-3 p-8 text-center">
            <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-surface-sunken text-fg-muted" aria-hidden="true">
                <x-icon name="presentation" class="size-6" />
            </span>
            <div class="space-y-1">
                <h2 class="text-base font-semibold">No classes yet</h2>
                <p class="mx-auto max-w-md text-sm text-fg-muted">Add a class you sat. You will record it, check what was said, and keep what the tutor taught.</p>
            </div>
            <div class="pt-2">
                <x-button variant="primary" icon="plus" wire:click="start">Add a class</x-button>
            </div>
        </div>
    @endif
</div>
