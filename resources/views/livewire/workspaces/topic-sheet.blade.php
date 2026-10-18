{{--
    One topic on a sheet (App\Livewire\Workspaces\TopicSheet): a bottom sheet on a phone, a side panel from 640 px.
    Top to bottom, the order a student needs it: which topic and where it lives; where they stand (the same status its
    row shows, so the two never disagree); its numbers; Study and its page; open questions and key points; renaming
    and moving folded away; removing it at the foot.
--}}
@php
    $cleanName = \App\Study\Topics::cleanMarkdown($topic?->name ?? '');
    $choices = [
        'not_started' => ['Not started', 'circle-dashed', 'No contact yet'],
        'covered' => ['Studying', 'book-open', 'Working on it'],
        'confused' => ['Not understood', 'circle-alert', 'Still confusing'],
        'understood' => ['Understood', 'circle-check', 'I can explain it'],
    ];
    $shown = $topic?->shown() ?? 'not_started';
    $current = $shown === 'mastered' ? 'understood' : $shown;
    $placeName = null;
    if ($topic) {
        foreach ($modules as $mod) {
            if ($mod->id === $topic->moduleId) {
                $placeName = $mod->title;
                foreach ($folders[$mod->id] ?? [] as $fld) {
                    if ($fld['id'] === $topic->folderId) {
                        $placeName = $fld['name'];
                    }
                }
            }
        }
    }
@endphp
<div>
    <dialog id="topic-sheet" class="modal tsheet" aria-labelledby="topic-sheet-title"
        wire:ignore.self
        x-data
        x-on:topic-sheet-dialog-open.window="$el.open || $el.showModal()"
        x-on:topic-sheet-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel tsheet-panel">
            @if ($topic)
                <header class="tsheet-head">
                    <span @class(['tsheet-icon', "is-{$current}"]) aria-hidden="true"><x-icon :name="$choices[$current][1]" class="size-5" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="tsheet-eyebrow">Topic{{ $placeName ? ' · in '.$placeName : '' }}</p>
                        <h2 id="topic-sheet-title" class="tsheet-title">{{ $cleanName }}</h2>
                    </div>
                    <x-ai-menu kind="topic" :id="$topic->id" :label="$cleanName" />
                    <button type="button" class="topbar-button shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" class="size-5" />
                    </button>
                </header>

                <div class="tsheet-body">
                    <fieldset class="tsheet-section">
                        <legend class="tsheet-label">Where you stand</legend>
                        <div class="tsheet-choices" role="radiogroup" aria-label="Where you stand on {{ $cleanName }}">
                            @foreach ($choices as $value => [$label, $icon, $hint])
                                <label @class(['tsheet-choice', "is-{$value}", 'is-active' => $value === $current]) wire:key="tsheet-choice-{{ $value }}">
                                    <input type="radio" class="sr-only" name="topic-sheet-status" value="{{ $value }}" wire:click="report('{{ $value }}')" @checked($value === $current)>
                                    <x-icon :name="$icon" class="size-4 shrink-0" />
                                    <span class="min-w-0">
                                        <span class="tsheet-choice-label">{{ $label }}</span>
                                        <span class="tsheet-choice-hint">{{ $hint }}</span>
                                    </span>
                                    <x-icon name="check" class="tsheet-choice-mark size-4" />
                                </label>
                            @endforeach
                        </div>
                        <div class="tsheet-meta" role="status" aria-live="polite">
                            @if ($notice)
                                <span class="tsheet-saved"><x-icon name="circle-check" class="size-3.5" />{{ $notice }}</span>
                            @endif
                            @if ($shown === 'mastered')
                                <span><x-icon name="shield-check" class="size-3.5" />Mastered: your reviews show it has stuck.</span>
                            @endif
                            <span>From your studying: {{ $topic->evidence() }}</span>
                            @php($atWord = $topic->statusAt !== null ? ', '.\Illuminate\Support\Carbon::parse($topic->statusAt, 'UTC')->format('j M') : '')
                            @if ($topic->statusSource() === 'tutor')
                                <span>Set by the tutor{{ $atWord }}. Pick another to change it.</span>
                            @elseif ($topic->statusSource() === 'chat')
                                <span>Marked by a chat app{{ $atWord }}. Pick another to change it.</span>
                            @elseif ($topic->statusSource() === 'accepted')
                                <span>You accepted the AI's word{{ $atWord }}. Pick another to change it.</span>
                            @elseif ($topic->statusSource() === 'stated_unverified')
                                <span>You said so{{ $atWord }} (may have been an accepted AI proposal).</span>
                            @endif
                        </div>
                        @error('status') <p class="field-error">{{ $message }}</p> @enderror
                    </fieldset>

                    <ul class="tsheet-stats" role="list">
                        <li>
                            @if ($cards['total'] > 0)
                                <a href="{{ route('workspaces.show', [$workspaceId, 'flashcards', 'topic' => $topic->id]) }}" class="tsheet-stat is-link">
                                    <span class="tsheet-stat-value">{{ $cards['total'] }}</span>
                                    <span class="tsheet-stat-label">{{ \Illuminate\Support\Str::plural('card', $cards['total']) }}@if ($cards['due'] > 0) · <span class="text-warning">{{ $cards['due'] }} due</span>@endif</span>
                                </a>
                            @else
                                <span class="tsheet-stat"><span class="tsheet-stat-value">0</span><span class="tsheet-stat-label">cards</span></span>
                            @endif
                        </li>
                        <li><span class="tsheet-stat"><span class="tsheet-stat-value">{{ count($open) }}</span><span class="tsheet-stat-label">open {{ \Illuminate\Support\Str::plural('question', count($open)) }}</span></span></li>
                        <li><span class="tsheet-stat"><span class="tsheet-stat-value">{{ $sessionCount }}</span><span class="tsheet-stat-label">{{ \Illuminate\Support\Str::plural('session', $sessionCount) }}</span></span></li>
                    </ul>

                    <div class="tsheet-actions">
                        @if ($topic->moduleId !== null)
                            <x-button variant="primary" icon="play" wire:click="study" wire:loading.attr="aria-busy" wire:target="study" busy-label="Starting…">Study this topic</x-button>
                        @endif
                        <a href="{{ route('workspaces.topics.show', [$workspaceId, $topic->id]) }}" class="btn btn-secondary">Open topic page<x-icon name="arrow-right" class="size-4" /></a>
                    </div>

                    @if ($open !== [])
                        <section class="tsheet-section" aria-labelledby="topic-sheet-questions">
                            <h3 id="topic-sheet-questions" class="tsheet-label">Open questions</h3>
                            <ul class="tsheet-list" role="list">
                                @foreach (array_slice($open, 0, 3) as $question)
                                    <li>
                                        <x-icon name="circle-help" class="size-4 shrink-0 text-fg-subtle" />
                                        <a href="{{ route('workspaces.questions.show', [$workspaceId, $question->id]) }}" class="tsheet-list-text item-link">{{ $question->text }}</a>
                                        @if ($question->status === 'stuck') <span class="badge badge-warning shrink-0">Stuck</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    <section class="tsheet-section" aria-labelledby="topic-sheet-points">
                        <h3 id="topic-sheet-points" class="tsheet-label">Key points @if ($points !== [])<span class="tsheet-count">{{ count($points) }}</span>@endif</h3>
                        @if ($points !== [])
                            <ul class="tsheet-list" role="list">
                                @foreach ($points as $keyPoint)
                                    <li wire:key="point-{{ $keyPoint->id }}">
                                        <span class="tsheet-dot" aria-hidden="true"></span>
                                        <span class="tsheet-list-text">{{ $keyPoint->text }}@if ($keyPoint->sourceName !== null)<span class="text-fg-muted"> · from {{ $keyPoint->sourceName }}{{ $keyPoint->locator ? ', '.$keyPoint->locator : '' }}</span>@endif</span>
                                        <button type="button" class="topbar-button tsheet-remove" wire:click="removePoint('{{ $keyPoint->id }}')" aria-label="Remove key point: {{ \Illuminate\Support\Str::limit($keyPoint->text, 40) }}"><x-icon name="x" class="size-4" /></button>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="tsheet-empty">The one-line facts worth remembering. Add your own, or the tutor adds them as you study.</p>
                        @endif
                        <form wire:submit="addPoint" novalidate class="tsheet-inline-form">
                            <label for="topic-sheet-point" class="sr-only">New key point</label>
                            <input id="topic-sheet-point" class="input" type="text" wire:model="point" maxlength="500" autocomplete="off" placeholder="Add a key point…">
                            <x-button type="submit" icon="plus" wire:loading.attr="aria-busy" wire:target="addPoint" busy-label="Adding…">Add</x-button>
                        </form>
                        @error('point') <p class="field-error">{{ $message }}</p> @enderror
                    </section>

                    <details class="tsheet-more" @if ($errors->hasAny(['name', 'place'])) open @endif>
                        <summary>
                            <x-icon name="pencil" class="size-4" />
                            <span class="flex-1">Rename or move</span>
                            <x-icon name="chevron-down" class="tsheet-more-chevron size-4" />
                        </summary>
                        <div class="tsheet-more-body">
                            <form wire:submit="rename" novalidate class="space-y-1.5">
                                <label for="topic-sheet-name" class="field-label">Name</label>
                                <div class="tsheet-inline-form">
                                    <input id="topic-sheet-name" class="input" type="text" wire:model="name" maxlength="120" autocomplete="off" placeholder="Topic name">
                                    <x-button type="submit" wire:loading.attr="aria-busy" wire:target="rename" busy-label="Saving…">Rename</x-button>
                                </div>
                                @error('name') <p class="field-error">{{ $message }}</p> @enderror
                            </form>
                            <div class="space-y-1.5">
                                <label for="topic-sheet-place" class="field-label">Module or folder</label>
                                <div class="tsheet-inline-form">
                                    <select id="topic-sheet-place" class="input" wire:model="place">
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
                                    <x-button wire:click="move" wire:loading.attr="aria-busy" wire:target="move" busy-label="Moving…">Move</x-button>
                                </div>
                                @error('place') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </details>
                </div>

                <footer class="tsheet-foot">
                    @if ($confirmingRemove)
                        <div class="tsheet-confirm" role="alert">
                            <p>Remove {{ $cleanName }}? What you did on it stays in your study record.</p>
                            <div class="tsheet-confirm-actions">
                                <x-button size="sm" wire:click="keep">Keep it</x-button>
                                <x-button size="sm" variant="danger" icon="trash-2" wire:click="remove" wire:loading.attr="aria-busy" wire:target="remove" busy-label="Removing…">Remove</x-button>
                            </div>
                        </div>
                    @else
                        <button type="button" class="btn btn-ghost btn-sm tsheet-danger" wire:click="askRemove">
                            <x-icon name="trash-2" class="size-4" />Remove topic
                        </button>
                    @endif
                </footer>
            @endif
        </div>
    </dialog>
</div>
