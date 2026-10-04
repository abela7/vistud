{{--
    Questions (App\Livewire\Workspaces\QuestionBoard): one line to write one
    down, a search, a filter by status and a sort, and the questions; each is a
    link to its own page (App\Livewire\Workspaces\QuestionPage). As a module's
    questions page (`page`) it has the section heading with Select and New
    question, and the questions are cards across the whole width; elsewhere
    (Progress, a study session) they are rows. Folded (a study session): no
    line, nothing until there is a question, then the list closed under its
    heading until opened, with a link to the module's questions page.
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
<div @class(['space-y-5' => $page]) x-data="selectable()" :class="{ 'is-selecting': isSelecting, 'is-selecting-container': isSelecting }" x-on:keydown.window="handleKeydown($event)" x-on:selection-clear.window="clearSelection()">
    @if ($page)
        {{-- The page's title and tabs are the module's (workspaces/questions.blade.php); here, what the student can do. --}}
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('workspaces.questions.create', [$workspaceId, 'module' => $moduleId]) }}" class="btn btn-primary">
                <x-icon name="plus" class="size-4" />New question
            </a>
            @if ($counts['all'] > 0)
                {{-- While selecting, the selection bar's Done ends it; the focus goes to its Select all. --}}
                <button type="button" class="btn btn-secondary" x-show="!isSelecting" x-on:click="toggleMode(); $nextTick(() => $root.querySelector('.selection-all-box input')?.focus())">
                    <x-icon name="list-checks" class="size-4" />Select
                </button>
            @endif
        </div>
    @endif
    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>
    @if (! $folded || $counts['all'] > 0)
        <section @class(['space-y-3', 'space-y-4' => $page]) aria-labelledby="questions-{{ $uid }}" @if ($folded) x-data="{ open: false }" @endif>
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
                        @unless ($page)
                            <button type="button" class="btn btn-sm btn-secondary" x-on:click="toggleMode()" :aria-pressed="isSelecting ? 'true' : 'false'">
                                <x-icon name="list-checks" class="size-4" />
                                <span x-text="isSelecting ? 'Done' : 'Select'">Select</span>
                            </button>
                        @endunless
                    </div>

                    <x-selection-bar>
                        <x-button variant="secondary" size="sm" icon="circle-dot" ::disabled="count === 0" x-on:click="$wire.bulk('status', selectedKeys(), { status: 'pending' })">Pending</x-button>
                        <x-button variant="secondary" size="sm" icon="circle-alert" ::disabled="count === 0" x-on:click="$wire.bulk('status', selectedKeys(), { status: 'stuck' })">Stuck</x-button>
                        <x-button variant="secondary" size="sm" icon="circle-check" ::disabled="count === 0" x-on:click="$wire.bulk('status', selectedKeys(), { status: 'answered' })">Answered</x-button>
                        <x-button variant="danger" size="sm" icon="trash-2" ::disabled="count === 0" x-on:click="$wire.openBulkRemove(selectedKeys())">Remove</x-button>
                    </x-selection-bar>
                @endif

                @if ($shown !== [])
                    @if ($page)
                        <ul class="question-grid" role="list" aria-label="Questions">
                            @foreach ($shown as $q)
                                @php
                                    [$icon, $colour] = $look[$q->status];
                                    $url = route('workspaces.questions.show', [$workspaceId, $q->id]);
                                @endphp
                                <li class="question-card ws-colour-{{ $colour }}" wire:key="question-{{ $q->id }}"
                                    data-select-key="question:{{ $q->id }}"
                                    :class="{ 'is-selected': isSelected('question:{{ $q->id }}') }"
                                    x-on:click="handleRowClick($event, 'question:{{ $q->id }}')">
                                    <div class="question-card-head">
                                        <x-selection-check key="question:{{ $q->id }}" label="Select {{ $q->text }}" />
                                        <span class="question-status"><x-icon :name="$icon" class="size-4" />{{ $q->statusLabel() }}</span>
                                    </div>
                                    <h3 class="question-card-text"><a href="{{ $url }}" class="tile-link">{{ $q->text }}</a></h3>
                                    @if ($q->answer)
                                        <div class="question-answer"><p class="question-answer-text">{{ $q->answer }}</p></div>
                                    @endif
                                    <p class="question-card-meta">
                                        <span class="question-meta-item"><x-icon name="clock" class="size-3.5 shrink-0" />{{ Carbon::parse($q->askedAt)->diffForHumans() }}</span>
                                        @if ($q->topicId !== null && isset($topicNames[$q->topicId]))
                                            <span class="question-meta-item">{{ $topicNames[$q->topicId] }}</span>
                                        @endif
                                        @if ($q->askTeacher)
                                            <span class="question-meta-item"><x-icon name="users" class="size-3.5 shrink-0" />For the teacher</span>
                                        @endif
                                    </p>
                                    <div class="question-card-menu">
                                        @include('livewire.workspaces.partials.row-menu', ['id' => 'q-'.$q->id, 'label' => Str::limit($q->text, 60), 'items' => array_values(array_filter([
                                            ['Open', 'pencil', null, false, $url],
                                            $q->status !== 'answered' ? ['Answered…', 'circle-check', null, false, route('workspaces.questions.show', [$workspaceId, $q->id, 'status' => 'answered'])] : null,
                                            $q->status !== 'stuck' ? ['Stuck', 'circle-alert', "mark('{$q->id}', 'stuck')", false] : null,
                                            $q->status !== 'pending' ? ['Pending', 'circle-dot', "mark('{$q->id}', 'pending')", false] : null,
                                        ]))])
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <ul class="item-list" role="list" aria-label="Questions">
                            @foreach ($shown as $q)
                                @php
                                    [$icon, $colour] = $look[$q->status];
                                    $url = route('workspaces.questions.show', [$workspaceId, $q->id]);
                                @endphp
                                <li class="item-row" wire:key="question-{{ $q->id }}"
                                    data-select-key="question:{{ $q->id }}"
                                    :class="{ 'is-selected': isSelected('question:{{ $q->id }}') }"
                                    x-on:click="handleRowClick($event, 'question:{{ $q->id }}')">
                                    <x-selection-check key="question:{{ $q->id }}" label="Select {{ $q->text }}" />
                                    <span class="item-icon ws-colour-{{ $colour }}" aria-hidden="true"><x-icon :name="$icon" class="size-5" /></span>
                                    <span class="min-w-0 flex-1">
                                        <a href="{{ $url }}" class="tile-link question-text">{{ $q->text }}</a>
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
                                        ['Open', 'pencil', null, false, $url],
                                        $q->status !== 'answered' ? ['Answered…', 'circle-check', null, false, route('workspaces.questions.show', [$workspaceId, $q->id, 'status' => 'answered'])] : null,
                                        $q->status !== 'stuck' ? ['Stuck', 'circle-alert', "mark('{$q->id}', 'stuck')", false] : null,
                                        $q->status !== 'pending' ? ['Pending', 'circle-dot', "mark('{$q->id}', 'pending')", false] : null,
                                    ]))])
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @elseif ($counts['all'] > 0)
                    <p class="text-sm text-fg-muted">{{ trim($search) !== '' ? 'No question matches.' : 'None '.Str::lower($tabs[$filter]).'.' }}</p>
                @elseif ($page)
                    <div class="empty-place">
                        <span class="item-icon ws-colour-blue" aria-hidden="true"><x-icon name="circle-help" class="size-5" /></span>
                        <p class="font-medium">No questions yet</p>
                        <p class="text-sm text-fg-muted">Write down what you don't get, in a line above or on a page of its own, and come back when it makes sense.</p>
                    </div>
                @endif
            </div>
        </section>
    @endif

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
