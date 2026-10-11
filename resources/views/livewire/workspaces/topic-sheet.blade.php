{{--
    One topic on a sheet (App\Livewire\Workspaces\TopicSheet):
    Compact, calm, simple popup with prominent "Not understand" button,
    unmistakable active status design, and a direct Deep Dive Topic Page link.
--}}
@php
    $cleanName = \App\Study\Topics::cleanMarkdown($topic?->name ?? '');
    $statusLabels = [
        'not_started' => ['label' => 'Not started', 'hint' => 'no contact yet'],
        'covered' => ['label' => 'Studying', 'hint' => 'Covered'],
        'confused' => ['label' => 'Not understand', 'hint' => 'Still confusing · Not understood'],
        'understood' => ['label' => 'Understood', 'hint' => ''],
    ];
    $statusIcons = [
        'not_started' => 'circle',
        'covered' => 'book-open',
        'confused' => 'circle-alert',
        'understood' => 'circle-check',
    ];
    $statusColours = [
        'not_started' => 'purple',
        'covered' => 'blue',
        'confused' => 'amber',
        'understood' => 'green',
    ];
@endphp
<div>
    <dialog id="topic-sheet" class="modal topic-sheet-modal" aria-labelledby="topic-sheet-title"
        wire:ignore.self
        x-data
        x-on:topic-sheet-dialog-open.window="$el.open || $el.showModal()"
        x-on:topic-sheet-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel w-full">
            @if ($topic)
                {{-- Compact Header: full width for title, no squished status chip --}}
                <div class="modal-head py-2.5 px-3.5 items-center">
                    <div class="min-w-0 flex-1">
                        <h2 id="topic-sheet-title" class="text-sm font-semibold leading-snug break-words text-fg">{{ $cleanName }}</h2>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <x-ai-menu kind="topic" :id="$topic->id" :label="$cleanName" />
                        <button type="button" class="topbar-button shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                            <x-icon name="x" class="size-4" />
                        </button>
                    </div>
                </div>

                <div class="space-y-2.5 px-3.5 py-2.5">
                    @if ($notice)
                        <p class="text-xs text-fg-muted bg-surface-sunken px-2.5 py-1 rounded-md border border-border/60" role="status">{{ $notice }}</p>
                    @endif

                    {{-- Status Choice Pills --}}
                    <fieldset class="space-y-1">
                        <div class="flex items-center justify-between gap-2">
                            <legend class="field-label text-[11px] uppercase tracking-wider text-fg-muted m-0">Where you stand</legend>
                            <span class="text-[11px] text-fg-muted">{{ $topic->evidence() }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-1.5" role="radiogroup">
                            @foreach ($statusLabels as $value => $meta)
                                @php
                                    $isChecked = ($value === 'not_started' && ($topic->status === null || $topic->status === 'not_started')) || $topic->status === $value;
                                    $isConfused = $value === 'confused';
                                @endphp
                                <label @class([
                                    'topic-choice status-choice cursor-pointer',
                                    'ws-colour-'.$statusColours[$value],
                                    'is-active' => $isChecked,
                                    'is-confused' => $isConfused,
                                ])>
                                    <input type="radio" name="topic-sheet-status" value="{{ $value }}" wire:click="report('{{ $value }}')" @checked($isChecked) class="sr-only">
                                    <x-icon :name="$statusIcons[$value]" class="size-3.5 shrink-0" />
                                    <span class="truncate font-semibold">{{ $meta['label'] }}@if ($meta['hint'] !== '') <span class="sr-only">({{ $meta['hint'] }})</span>@endif</span>
                                    @if ($isChecked)
                                        <span class="topic-choice-check" title="Current status">
                                            <x-icon name="check" class="size-2 text-surface-raised" />
                                        </span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                        @if ($topic->byTutor())
                            <p class="text-[11px] text-fg-muted mt-1 leading-tight">Set by the tutor{{ $topic->statusAt !== null ? ', '.\Illuminate\Support\Carbon::parse($topic->statusAt, 'UTC')->format('j M') : '' }}. Pick another to change it.</p>
                        @endif
                        @error('status') <p class="field-error text-[11px]">{{ $message }}</p> @enderror
                    </fieldset>

                    {{-- Compact Combined Stats + Deep Dive Bar --}}
                    <div class="flex flex-wrap items-center justify-between gap-2 p-2 rounded-lg bg-surface-sunken text-[11px] text-fg-muted border border-border/60">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            <span>
                                Cards:
                                @if ($cards['total'] > 0)
                                    <a href="{{ route('workspaces.show', [$workspaceId, 'flashcards', 'topic' => $topic->id]) }}" class="font-semibold text-accent" style="text-decoration: none !important;">
                                        {{ $cards['total'] }}{{ $cards['due'] > 0 ? ' ('.$cards['due'].' due)' : '' }}
                                    </a>
                                @else
                                    <strong class="text-fg">0</strong>
                                @endif
                            </span>
                            <span class="text-border" aria-hidden="true">·</span>
                            <span>Questions: <strong class="text-fg">{{ count($open) }} open</strong></span>
                            <span class="text-border" aria-hidden="true">·</span>
                            <span>Sessions: <strong class="text-fg">{{ $sessionCount }}</strong></span>
                        </div>

                        <a href="{{ route('workspaces.topics.show', [$workspaceId, $topic->id]) }}" class="btn btn-primary btn-xs text-[11px] py-1 px-2.5 inline-flex items-center gap-1 shrink-0" style="text-decoration: none !important;">
                            <x-icon name="sparkles" class="size-3 text-on-primary" />
                            <span>More options & Deep Dive</span>
                            <x-icon name="arrow-right" class="size-3" />
                        </a>
                    </div>

                    {{-- Open questions preview (if any) --}}
                    @if ($open !== [])
                        <section class="space-y-1" aria-labelledby="topic-sheet-questions">
                            <h3 id="topic-sheet-questions" class="text-[11px] font-semibold text-fg-muted uppercase tracking-wider">Open questions</h3>
                            <ul class="grid gap-1 text-xs" role="list">
                                @foreach (array_slice($open, 0, 2) as $question)
                                    <li class="p-1.5 rounded-md bg-surface-sunken border border-border/50 flex items-center justify-between gap-1 text-xs">
                                        <a href="{{ route('workspaces.questions.show', [$workspaceId, $question->id]) }}" class="item-link truncate" style="text-decoration: none !important;">{{ $question->text }}</a>
                                        @if ($question->status === 'stuck') <span class="text-warning font-semibold shrink-0">· stuck</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    {{-- Key points, rename & move (open by default so there's zero dead space and quick access) --}}
                    <details open class="group rounded-lg border border-border/60 bg-surface-sunken overflow-hidden text-xs">
                        <summary class="cursor-pointer px-2.5 py-1.5 text-[11px] font-semibold text-fg-muted hover:text-fg flex items-center justify-between select-none">
                            <span>Key points, rename & move</span>
                            <x-icon name="chevron-down" class="size-3 transition-transform group-open:rotate-180" />
                        </summary>

                        <div class="p-2.5 pt-2 space-y-2 border-t border-border/40 bg-surface-raised">
                            {{-- Key Points --}}
                            <section class="space-y-1" aria-labelledby="topic-sheet-points">
                                <h3 id="topic-sheet-points" class="field-label text-[11px] m-0">Key points</h3>
                                @if ($points !== [])
                                    <ul class="grid gap-1 text-xs" role="list">
                                        @foreach ($points as $keyPoint)
                                            <li class="flex items-start gap-1.5 px-2 py-1 rounded bg-surface-sunken border border-border/50 text-xs" wire:key="point-{{ $keyPoint->id }}">
                                                <span class="min-w-0 flex-1 break-words">{{ $keyPoint->text }}@if ($keyPoint->sourceName !== null)<span class="text-fg-muted"> · from {{ $keyPoint->sourceName }}{{ $keyPoint->locator ? ', '.$keyPoint->locator : '' }}</span>@endif</span>
                                                <button type="button" class="topbar-button -my-1 shrink-0" wire:click="removePoint('{{ $keyPoint->id }}')" aria-label="Remove key point: {{ \Illuminate\Support\Str::limit($keyPoint->text, 40) }}"><x-icon name="x" class="size-3" /></button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                <form wire:submit="addPoint" novalidate class="flex items-center gap-1.5">
                                    <div class="min-w-0 flex-1">
                                        <label for="topic-sheet-point" class="sr-only">New key point</label>
                                        <input id="topic-sheet-point" class="input text-xs py-1 px-2 w-full" type="text" wire:model="point" maxlength="500" autocomplete="off" placeholder="Add a key point…">
                                        @error('point') <p class="field-error text-[11px] mt-0.5">{{ $message }}</p> @enderror
                                    </div>
                                    <x-button size="xs" type="submit" wire:loading.attr="aria-busy" wire:target="addPoint" busy-label="Adding…">Add</x-button>
                                </form>
                            </section>

                            {{-- Rename & Move --}}
                            <div class="grid grid-cols-1 gap-1.5 pt-1.5 border-t border-border/40">
                                <form wire:submit="rename" novalidate class="flex items-center gap-1.5">
                                    <div class="min-w-0 flex-1">
                                        <label for="topic-sheet-name" class="sr-only">Name</label>
                                        <input id="topic-sheet-name" class="input text-xs py-1 px-2 w-full" type="text" wire:model="name" maxlength="120" autocomplete="off" placeholder="Topic name">
                                        @error('name') <p class="field-error text-[11px] mt-0.5">{{ $message }}</p> @enderror
                                    </div>
                                    <x-button size="xs" type="submit" wire:loading.attr="aria-busy" wire:target="rename" busy-label="Saving…">Rename</x-button>
                                </form>

                                <div class="flex items-center gap-1.5">
                                    <div class="min-w-0 flex-1">
                                        <label for="topic-sheet-place" class="sr-only">Module or folder</label>
                                        <select id="topic-sheet-place" class="input text-xs py-1 px-2 w-full" wire:model="place">
                                            <option value="">No module</option>
                                            @foreach ($modules as $mod)
                                                @if (($folders[$mod->id] ?? []) === [])
                                                    <option value="module:{{ $mod->id }}">{{ $mod->title }}</option>
                                                @else
                                                    <optgroup label="{{ $mod->title }}">
                                                        <option value="module:{{ $mod->id }}">In the module</option>
                                                        @foreach ($folders[$mod->id] as $fld)
                                                            <option value="folder:{{ $fld['id'] }}">{{ $fld['name'] }}</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                            @endforeach
                                        </select>
                                        @error('place') <p class="field-error text-[11px] mt-0.5">{{ $message }}</p> @enderror
                                    </div>
                                    <x-button size="xs" wire:click="move" wire:loading.attr="aria-busy" wire:target="move" busy-label="Moving…">Move</x-button>
                                </div>
                            </div>
                        </div>
                    </details>
                </div>

                {{-- Modal Footer --}}
                @if ($confirmingRemove)
                    <div class="px-3.5 pt-1.5">
                        <p class="text-xs text-danger font-medium">Remove {{ $cleanName }}? What you did on it stays in your study record.</p>
                    </div>
                    <div class="modal-actions py-2 px-3.5">
                        <x-button size="xs" wire:click="keep">Keep it</x-button>
                        <x-button size="xs" variant="danger" icon="trash-2" wire:click="remove" wire:loading.attr="aria-busy" wire:target="remove" busy-label="Removing…">Remove</x-button>
                    </div>
                @else
                    <div class="modal-actions py-2 px-3.5 justify-between items-center">
                        <button type="button" class="btn btn-ghost btn-xs text-danger hover:opacity-80 text-xs py-0.5 px-1.5" wire:click="askRemove">
                            <x-icon name="trash-2" class="size-3" />
                            <span>Remove topic</span>
                        </button>
                        <button type="button" class="btn btn-secondary btn-xs py-1 px-2.5 text-xs" x-on:click="$el.closest('dialog').close()">Close</button>
                        <button type="button" class="sr-only" wire:click="study" aria-hidden="true" tabindex="-1">Study</button>
                    </div>
                @endif
            @endif
        </div>
    </dialog>
</div>
