{{--
    One topic on a sheet (App\Livewire\Workspaces\TopicSheet): where the student says they stand, its cards, open
    questions and key points, its sessions; rename, move, Study. Opened from a topic's row.
--}}
@php
    $statusWords = ['covered' => 'Covered', 'understood' => 'Understood', 'confused' => 'Still confusing'];
    $statusIcons = ['covered' => 'check', 'understood' => 'circle-check', 'confused' => 'circle-alert'];
    $statusColours = ['covered' => 'blue', 'understood' => 'green', 'confused' => 'amber'];
@endphp
<div>
    <dialog id="topic-sheet" class="modal" aria-labelledby="topic-sheet-title"
        wire:ignore.self
        x-data
        x-on:topic-sheet-dialog-open.window="$el.open || $el.showModal()"
        x-on:topic-sheet-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel">
            @if ($topic)
                <div class="modal-head">
                    <h2 id="topic-sheet-title" class="min-w-0 flex-1 text-lg font-semibold break-words">{{ $topic->name }}</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()"><x-icon name="x" /></button>
                </div>
                <div class="space-y-5 px-5 pt-2">
                    @if ($notice)
                        <p class="text-sm text-fg-muted" role="status">{{ $notice }}</p>
                    @endif

                    <fieldset class="space-y-2">
                        <legend class="field-label">Where you stand</legend>
                        <div class="status-choices">
                            @foreach ($statusWords as $value => $word)
                                <label @class(['status-choice', 'ws-colour-'.$statusColours[$value]])>
                                    <input type="radio" name="topic-sheet-status" value="{{ $value }}" wire:click="report('{{ $value }}')" @checked($topic->status === $value)>
                                    <x-icon :name="$statusIcons[$value]" class="size-5" />
                                    <span>{{ $word }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('status') <p class="field-error">{{ $message }}</p> @enderror
                    </fieldset>

                    <ul class="grid gap-1 text-sm" role="list">
                        <li>
                            @if ($cards['total'] > 0)
                                <a href="{{ route('workspaces.show', [$workspaceId, 'flashcards', 'topic' => $topic->id]) }}" class="item-link">{{ Str::plural('card', $cards['total'], prependCount: true) }}{{ $cards['due'] > 0 ? ', '.$cards['due'].' due' : '' }}</a>
                            @else
                                <span class="text-fg-muted">No cards yet</span>
                            @endif
                        </li>
                        <li class="text-fg-muted">{{ Str::plural('session', $sessionCount, prependCount: true) }}</li>
                    </ul>

                    @if ($open !== [])
                        <section class="space-y-1" aria-labelledby="topic-sheet-questions">
                            <h3 id="topic-sheet-questions" class="field-label">Open questions</h3>
                            <ul class="grid gap-1 text-sm" role="list">
                                @foreach (array_slice($open, 0, 5) as $question)
                                    <li><a href="{{ route('workspaces.questions.show', [$workspaceId, $question->id]) }}" class="item-link">{{ $question->text }}</a>@if ($question->status === 'stuck') <span class="text-fg-muted">· stuck</span>@endif</li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if ($points !== [])
                        <section class="space-y-1" aria-labelledby="topic-sheet-points">
                            <h3 id="topic-sheet-points" class="field-label">Key points</h3>
                            <ul class="grid gap-1 text-sm" role="list">
                                @foreach (array_slice($points, 0, 8) as $point)
                                    <li>{{ $point->text }}</li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    <form wire:submit="rename" novalidate class="flex items-end gap-2">
                        <div class="min-w-0 flex-1">
                            <x-field name="name" label="Name" wire:model="name" maxlength="120" autocomplete="off" />
                        </div>
                        <x-button type="submit" wire:loading.attr="aria-busy" wire:target="rename" busy-label="Saving…">Rename</x-button>
                    </form>

                    <div class="flex items-end gap-2">
                        <div class="field min-w-0 flex-1">
                            <label for="topic-sheet-module" class="field-label">Module</label>
                            <select id="topic-sheet-module" class="input" wire:model="moduleId">
                                <option value="">No module</option>
                                @foreach ($modules as $module)
                                    <option value="{{ $module->id }}">{{ $module->title }}</option>
                                @endforeach
                            </select>
                            @error('moduleId') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <x-button wire:click="move" wire:loading.attr="aria-busy" wire:target="move" busy-label="Moving…">Move</x-button>
                    </div>
                </div>
                <div class="modal-actions">
                    <x-button x-on:click="$el.closest('dialog').close()">Close</x-button>
                    <x-button variant="primary" icon="play" wire:click="study">Study</x-button>
                </div>
            @endif
        </div>
    </dialog>
</div>
