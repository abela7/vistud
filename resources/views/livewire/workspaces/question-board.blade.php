{{--
    Questions (App\Livewire\Workspaces\QuestionBoard): one line to write one
    down, a search, a filter by status and a sort, the list, and the side
    panel for one question. Folded (a study session): no line, nothing until
    there is a question, then the list closed under its heading until opened,
    with a link to the module's questions page.
--}}
@php
    use App\Livewire\Workspaces\QuestionBoard;
    use App\Study\Questions;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $look = ['pending' => ['circle-dot', 'blue'], 'stuck' => ['circle-alert', 'red'], 'answered' => ['circle-check', 'green']];
    $tabs = ['all' => 'All'] + Questions::STATUSES;
    $uid = $this->getId();
    $open = $counts['pending'] + $counts['stuck'];
@endphp
<div x-data="selectable()" :class="{ 'is-selecting': isSelecting, 'is-selecting-container': isSelecting }" x-on:keydown.window="handleKeydown($event)" x-on:selection-clear.window="clearSelection()">
    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" />
    </div>
    @if (! $folded || $counts['all'] > 0)
        <section class="space-y-3" aria-labelledby="questions-{{ $uid }}" @if ($folded) x-data="{ open: false }" @endif>
            @if ($folded)
                <div class="fold-head">
                    <h2 class="min-w-0 flex-1">
                        <button type="button" id="questions-{{ $uid }}" class="fold-toggle" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-controls="questions-body-{{ $uid }}">
                            <x-icon name="chevron-right" class="size-4 transition-transform" x-bind:class="open && 'rotate-90'" />
                            <span class="section-title">Questions</span>
                            <span class="item-meta">{{ implode(' · ', array_filter([$open > 0 ? $open.' open' : null, $counts['stuck'] > 0 ? $counts['stuck'].' stuck' : null, $open === 0 ? 'all answered' : null])) }}</span>
                        </button>
                    </h2>
                    @if ($moduleId)
                        <a href="{{ route('workspaces.modules.questions', [$workspaceId, $moduleId]) }}" class="text-link text-sm">All questions</a>
                    @endif
                </div>
            @else
                @if ($level === 3)
                    <h3 id="questions-{{ $uid }}" @class(['section-title', 'sr-only' => $page])>Questions</h3>
                @else
                    <h2 id="questions-{{ $uid }}" @class(['section-title', 'sr-only' => $page])>{{ $page ? 'All questions' : 'Questions' }}</h2>
                @endif
                <form wire:submit="add" class="question-add" novalidate>
                    <label for="question-add-{{ $uid }}" class="sr-only">A question you don't get yet</label>
                    <span class="item-icon ws-colour-blue" aria-hidden="true"><x-icon name="circle-help" class="size-5" /></span>
                    <input id="question-add-{{ $uid }}" type="text" class="question-add-input" wire:model="text" maxlength="{{ Questions::MAX_TEXT }}" placeholder="What don't you get? Write it down…" autocomplete="off">
                    <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="add" busy-label="Adding…">Add</x-button>
                </form>
                @error('text') <p class="field-error">{{ $message }}</p> @enderror
            @endif

            <div class="space-y-3" id="questions-body-{{ $uid }}" @if ($folded) x-show="open" x-cloak @endif>
                @if ($counts['all'] > 0)
                    <div class="question-tools">
                        @unless ($folded)
                            <div class="search-field">
                                <x-icon name="search" class="size-4" />
                                <label for="question-search-{{ $uid }}" class="sr-only">Search the questions</label>
                                <input id="question-search-{{ $uid }}" type="search" class="input" placeholder="Search" wire:model.live.debounce.250ms="search" autocomplete="off">
                            </div>
                        @endunless
                        <div class="segmented segmented-sm" role="group" aria-label="Show questions">
                            @foreach ($tabs as $key => $word)
                                <button type="button" @class(['segmented-option', 'is-current' => $filter === $key]) wire:click="show('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">
                                    {{ $word }} <span class="tab-count">{{ $counts[$key] }}</span>
                                </button>
                            @endforeach
                        </div>
                        @unless ($folded)
                            <div class="flex items-center gap-2">
                                <label for="question-sort-{{ $uid }}" class="text-sm text-fg-muted">Sort</label>
                                <select id="question-sort-{{ $uid }}" class="input input-sm" wire:model.live="sort">
                                    @foreach (QuestionBoard::SORTS as $key => $word)
                                        <option value="{{ $key }}">{{ $word }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endunless
                        <button type="button" class="btn btn-sm btn-secondary" x-on:click="toggleMode()" aria-label="Select questions" :aria-pressed="isSelecting ? 'true' : 'false'">
                            <x-icon name="list-checks" class="size-4" />
                            <span x-text="isSelecting ? 'Done' : 'Select'"></span>
                        </button>
                    </div>

                    <x-selection-bar>
                        <x-button variant="secondary" size="sm" icon="circle-dot" ::disabled="count === 0" x-on:click="$wire.bulk('status', selectedKeys(), { status: 'pending' })">Pending</x-button>
                        <x-button variant="secondary" size="sm" icon="circle-alert" ::disabled="count === 0" x-on:click="$wire.bulk('status', selectedKeys(), { status: 'stuck' })">Stuck</x-button>
                        <x-button variant="secondary" size="sm" icon="circle-check" ::disabled="count === 0" x-on:click="$wire.bulk('status', selectedKeys(), { status: 'answered' })">Answered</x-button>
                        <x-button variant="danger" size="sm" icon="trash-2" ::disabled="count === 0" x-on:click="$wire.openBulkRemove(selectedKeys())">Remove</x-button>
                    </x-selection-bar>
                @endif

                @if ($shown !== [])
                    <ul class="item-list" role="list" aria-label="Questions">
                        @foreach ($shown as $q)
                            @php [$icon, $colour] = $look[$q->status]; @endphp
                            <li class="item-row" wire:key="question-{{ $q->id }}"
                                data-select-key="question:{{ $q->id }}"
                                :class="{ 'is-selected': isSelected('question:{{ $q->id }}') }"
                                x-on:click="handleRowClick($event, 'question:{{ $q->id }}')">
                                <x-selection-check key="question:{{ $q->id }}" label="Select {{ $q->text }}" />
                                <span class="item-icon ws-colour-{{ $colour }}" aria-hidden="true"><x-icon :name="$icon" class="size-5" /></span>
                                <span class="min-w-0 flex-1">
                                    <button type="button" class="tile-link question-text" wire:click="open('{{ $q->id }}')">{{ $q->text }}</button>
                                    <span class="item-meta">{{ implode(' · ', array_filter([
                                        $q->statusLabel(),
                                        $q->topicId !== null ? ($topicNames[$q->topicId] ?? null) : null,
                                        $q->askTeacher ? 'for the teacher' : null,
                                        Carbon::parse($q->askedAt)->diffForHumans(),
                                    ])) }}</span>
                                    @if ($q->status === 'answered' && $q->answer)
                                        <span class="question-answer">{{ Str::limit($q->answer, 160) }}</span>
                                    @endif
                                </span>
                                @include('livewire.workspaces.partials.row-menu', ['id' => 'q-'.$q->id, 'label' => Str::limit($q->text, 60), 'items' => array_values(array_filter([
                                    $q->status !== 'answered' ? ['Answered', 'circle-check', "open('{$q->id}', 'answered')", false] : null,
                                    $q->status !== 'stuck' ? ['Stuck', 'circle-alert', "mark('{$q->id}', 'stuck')", false] : null,
                                    $q->status !== 'pending' ? ['Pending', 'circle-dot', "mark('{$q->id}', 'pending')", false] : null,
                                    ['Open', 'pencil', "open('{$q->id}')", false],
                                ]))])
                            </li>
                        @endforeach
                    </ul>
                @elseif ($counts['all'] > 0)
                    <p class="text-sm text-fg-muted">{{ trim($search) !== '' ? 'No question matches.' : 'None '.Str::lower($tabs[$filter]).'.' }}</p>
                @endif
            </div>
        </section>
    @endif

    <dialog id="question-dialog" class="modal" aria-labelledby="question-dialog-title"
        wire:ignore.self
        x-data
        x-on:question-dialog-open.window="$el.open || $el.showModal()"
        x-on:question-dialog-close.window="$el.open && $el.close()"
        x-init="$wire.editing && $nextTick(() => $el.open || $el.showModal())"
        x-on:close="$wire.editing && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($editing)
            <form wire:submit="save" novalidate class="modal-panel" wire:key="question-{{ $editing }}" x-data="{ sure: false }">
                <div class="modal-head">
                    <h2 id="question-dialog-title" class="min-w-0 flex-1 text-lg font-semibold">{{ $editing === 'new' ? 'New question' : 'Question' }}</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>
                <div class="space-y-5 px-5">
                    <div class="field">
                        <label for="question-text" class="field-label">Question</label>
                        <textarea id="question-text" class="input" rows="3" maxlength="{{ Questions::MAX_TEXT }}" wire:model="question" @if ($editing === 'new') autofocus @endif></textarea>
                        @error('question') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="field-label mb-2">Status</legend>
                        <div class="status-choices">
                            @foreach (Questions::STATUSES as $key => $word)
                                <label @class(['status-choice', "ws-colour-{$look[$key][1]}"])>
                                    <input type="radio" name="question-status" value="{{ $key }}" wire:model.live="status">
                                    <x-icon :name="$look[$key][0]" class="size-5" />
                                    <span>{{ $word }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('status') <p class="field-error">{{ $message }}</p> @enderror
                    </fieldset>

                    <div class="field">
                        <label for="question-answer" class="field-label">Answer <span class="font-normal text-fg-muted">(optional)</span></label>
                        <textarea id="question-answer" class="input" rows="{{ $status === 'answered' ? 5 : 3 }}" maxlength="{{ Questions::MAX_ANSWER }}" wire:model="answer" placeholder="What you found out, in your own words" @if ($editing !== 'new' && $status === 'answered') autofocus @endif></textarea>
                        @error('answer') <p class="field-error">{{ $message }}</p> @enderror
                    </div>

                    @if ($editing === 'new' && $topics !== [])
                        <div class="field">
                            <label for="question-topic" class="field-label">Topic <span class="font-normal text-fg-muted">(optional)</span></label>
                            <select id="question-topic" class="input" wire:model="topicId">
                                <option value="">None</option>
                                @foreach ($topics as $topic)
                                    <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <x-checkbox name="askTeacher" label="Ask the teacher" wire:model="askTeacher" />
                </div>
                <div class="modal-actions">
                    @if ($editing !== 'new')
                        <button type="button" class="btn btn-ghost mr-auto" x-show="! sure" x-on:click="sure = true"><x-icon name="trash-2" class="size-4" />Delete</button>
                        <button type="button" class="btn btn-danger mr-auto" x-show="sure" x-cloak wire:click="delete">Delete for good</button>
                    @endif
                    <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                    <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save</x-button>
                </div>
            </form>
        @endif
    </dialog>

    <dialog id="bulk-question-dialog" class="modal" aria-labelledby="bulk-question-dialog-title"
        wire:ignore.self
        x-data
        x-on:bulk-question-dialog-open.window="$el.open || $el.showModal()"
        x-on:bulk-question-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.bulkKeys = []"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel" role="document">
            <div class="modal-head">
                <h2 id="bulk-question-dialog-title" class="min-w-0 flex-1 text-lg font-semibold">Remove questions?</h2>
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                    <x-icon name="x" />
                </button>
            </div>
            <div class="space-y-4 px-5 pt-2">
                <p class="text-sm text-fg-muted">
                    Remove {{ count($bulkKeys) }} {{ Str::plural('question', count($bulkKeys)) }} from this board?
                </p>
            </div>
            <div class="modal-actions">
                <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                <x-button variant="danger" wire:click="confirmBulkRemove" wire:loading.attr="aria-busy" busy-label="Removing…">Remove</x-button>
            </div>
        </div>
    </dialog>
</div>
