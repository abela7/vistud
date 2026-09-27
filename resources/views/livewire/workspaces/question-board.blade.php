{{--
    Questions (App\Livewire\Workspaces\QuestionBoard): one line to write one
    down, a filter by status, the list, and the side panel for one question.
    Quiet (a module's page, a study session): no line, and nothing at all
    until there is a question; the page's own button opens the panel.
--}}
@php
    use App\Study\Questions;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $look = ['pending' => ['circle-dot', 'blue'], 'stuck' => ['circle-alert', 'red'], 'answered' => ['circle-check', 'green']];
    $tabs = ['all' => 'All'] + Questions::STATUSES;
    $uid = $this->getId();
@endphp
<div>
    <x-toast :message="$notice" />
    @if (! $quiet || $counts['all'] > 0)
        <section class="space-y-3" aria-labelledby="questions-{{ $uid }}">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    @if ($level === 3)
                        <h3 id="questions-{{ $uid }}" class="section-title">Questions</h3>
                    @else
                        <h2 id="questions-{{ $uid }}" class="section-title">Questions</h2>
                    @endif
                    @if ($quiet)
                        <button type="button" class="text-link text-sm" wire:click="create">Ask another</button>
                    @endif
                </div>
                @if ($counts['all'] > 0)
                    <div class="segmented segmented-sm" role="group" aria-label="Show questions">
                        @foreach ($tabs as $key => $word)
                            <button type="button" @class(['segmented-option', 'is-current' => $filter === $key]) wire:click="show('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">
                                {{ $word }} <span class="tab-count">{{ $counts[$key] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            @unless ($quiet)
                <form wire:submit="add" class="question-add" novalidate>
                    <label for="question-add-{{ $uid }}" class="sr-only">A question you don't get yet</label>
                    <span class="item-icon ws-colour-blue" aria-hidden="true"><x-icon name="circle-help" class="size-5" /></span>
                    <input id="question-add-{{ $uid }}" type="text" class="question-add-input" wire:model="text" maxlength="{{ Questions::MAX_TEXT }}" placeholder="What don't you get? Write it down…" autocomplete="off">
                    <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="add" busy-label="Adding…">Add</x-button>
                </form>
                @error('text') <p class="field-error">{{ $message }}</p> @enderror
            @endunless

            @if ($shown !== [])
                <ul class="item-list" role="list" aria-label="Questions">
                    @foreach ($shown as $q)
                        @php [$icon, $colour] = $look[$q->status]; @endphp
                        <li class="item-row" wire:key="question-{{ $q->id }}">
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
                <p class="text-sm text-fg-muted">None {{ Str::lower($tabs[$filter]) }}.</p>
            @endif
        </section>
    @endif

    <dialog id="question-dialog" class="modal" aria-labelledby="question-dialog-title"
        wire:ignore.self
        x-data
        x-on:question-dialog-open.window="$el.open || $el.showModal()"
        x-on:question-dialog-close.window="$el.open && $el.close()"
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
</div>
