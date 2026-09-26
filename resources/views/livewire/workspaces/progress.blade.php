{{--
    A workspace's Progress section (App\Livewire\Workspaces\Progress): the
    topics, each with the student's own status and what the evidence says,
    and the questions they registered.
--}}
@php
    use App\Study\TopicDetails;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $statusWords = ['not_started' => 'Not started', 'covered' => 'Covered', 'understood' => 'Understood', 'confused' => 'Confused', 'mastered' => 'Mastered'];
    $statusIcons = ['not_started' => 'circle-dot', 'covered' => 'check', 'understood' => 'circle-check', 'confused' => 'circle-alert', 'mastered' => 'shield-check'];
    $headings = ['topic' => $targetId === null ? 'New topic' : 'Rename topic', 'move' => 'Move “'.$target.'”', 'question' => 'New question'];
    $submit = ['topic' => $targetId === null ? 'Add topic' : 'Rename', 'move' => 'Move', 'question' => 'Add question'];
    $groups = [...array_map(fn ($m) => [$m->id, $m->title], $modules), ['', $modules === [] ? '' : 'Not in a module']];
@endphp
<div class="space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <ul class="flex flex-wrap gap-2" role="list" aria-label="Topics by status">
            @foreach ($counts as $status => $count)
                <li @class(['status-count', "status-{$status}"])><x-icon :name="$statusIcons[$status]" class="size-4" />{{ $count }} {{ strtolower($statusWords[$status]) }}</li>
            @endforeach
        </ul>
        <div class="flex flex-wrap gap-2">
            <x-button icon="circle-help" wire:click="newQuestion">New question</x-button>
            <x-button variant="primary" icon="plus" wire:click="newTopic">New topic</x-button>
        </div>
    </div>

    <div role="status" aria-live="polite">
        @if ($notice)
            <x-alert tone="success" :live="false">{{ $notice }}</x-alert>
        @endif
    </div>

    <section aria-labelledby="topics-heading" class="space-y-4">
        <h2 id="topics-heading" class="text-lg font-semibold">Topics</h2>
        @if ($topics === [])
            <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border-strong px-6 py-12 text-center">
                <span class="ws-chip size-14"><x-icon name="trending-up" class="size-6" /></span>
                <h3 class="text-lg font-semibold">No topics yet</h3>
                <p class="max-w-md text-fg-muted">Topics are the spine of the course, like “Joins” or “Normalisation”. Add them as you go, and say for each one whether it's covered, understood or still confusing. The evidence line under each shows what your practice says.</p>
            </div>
        @else
            @foreach ($groups as [$groupId, $groupTitle])
                @if (isset($byModule[$groupId]))
                    <div class="space-y-2" wire:key="group-{{ $groupId ?: 'none' }}">
                        @if ($groupTitle !== '')
                            <h3 class="text-sm font-semibold text-fg-muted">{{ $groupTitle }}</h3>
                        @endif
                        <ul class="module-card divide-y divide-divider" role="list">
                            @foreach ($byModule[$groupId] as $topic)
                                @php $shown = $topic->shown(); $i = array_search($topic, $topics, true); @endphp
                                <li wire:key="topic-{{ $topic->id }}" class="topic-row">
                                    <div class="topic-main">
                                        <p class="flex flex-wrap items-center gap-2">
                                            <span class="font-semibold break-words">{{ $topic->name }}</span>
                                            <span @class(['status-chip', "status-{$shown}"])><x-icon :name="$statusIcons[$shown]" class="size-3.5" />{{ $statusWords[$shown] }}</span>
                                        </p>
                                        <p class="text-sm text-fg-muted">Evidence: {{ $topic->evidence() }}</p>
                                    </div>
                                    <div class="segmented" role="group" aria-label="Status of {{ $topic->name }}">
                                        @foreach (['covered' => 'Covered', 'understood' => 'Understood', 'confused' => 'Confused'] as $status => $word)
                                            <button type="button" @class(['segmented-option', 'is-current' => $topic->status === $status]) wire:click="report('{{ $topic->id }}', '{{ $status }}')" @if ($topic->status === $status) aria-pressed="true" @else aria-pressed="false" @endif>
                                                <x-icon :name="$statusIcons[$status]" class="size-4" />{{ $word }}
                                            </button>
                                        @endforeach
                                    </div>
                                    @include('livewire.workspaces.partials.row-menu', ['id' => $topic->id, 'label' => $topic->name, 'items' => [
                                        ['New question about it', 'circle-help', "newQuestion('{$topic->id}')", false],
                                        ['Rename', 'pencil', "renameTopic('{$topic->id}')", false],
                                        ['Move to module…', 'folder-input', "moveTopic('{$topic->id}')", $modules === []],
                                        ['Move up', 'arrow-up', "moveTopicBy('{$topic->id}', -1)", $i === 0],
                                        ['Move down', 'arrow-down', "moveTopicBy('{$topic->id}', 1)", $i === count($topics) - 1],
                                        ['Remove', 'trash-2', "retireTopic('{$topic->id}')", false],
                                    ]])
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        @endif
    </section>

    <section aria-labelledby="questions-heading" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="questions-heading" class="text-lg font-semibold">Questions <span class="font-normal text-fg-muted">({{ count($open) }} open)</span></h2>
            @if ($understood !== [])
                <x-button variant="ghost" wire:click="toggleUnderstood" aria-expanded="{{ $showUnderstood ? 'true' : 'false' }}" aria-controls="understood-questions">
                    {{ $showUnderstood ? 'Hide' : 'Show' }} {{ count($understood) }} understood
                </x-button>
            @endif
        </div>
        @if ($open === [] && $understood === [])
            <p class="rounded-xl border border-dashed border-border-strong px-6 py-6 text-center text-fg-muted">Nothing registered yet. When something isn't clear, write it here: you'll go through the open ones later, or take them to your teacher.</p>
        @else
            <ul class="module-card divide-y divide-divider" role="list" aria-label="Open questions">
                @forelse ($open as $question)
                    @include('livewire.workspaces.partials.question-row')
                @empty
                    <li class="px-4 py-3 text-sm text-fg-muted">No open questions.</li>
                @endforelse
            </ul>
            @if ($showUnderstood && $understood !== [])
                <ul id="understood-questions" class="module-card divide-y divide-divider" role="list" aria-label="Understood questions">
                    @foreach ($understood as $question)
                        @include('livewire.workspaces.partials.question-row')
                    @endforeach
                </ul>
            @endif
        @endif
    </section>

    <dialog id="progress-dialog" class="modal" aria-labelledby="progress-dialog-title"
        wire:ignore.self
        x-data
        x-on:progress-dialog-open.window="$el.open || $el.showModal()"
        x-on:progress-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.mode && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($mode)
            <form wire:submit="save" novalidate class="modal-panel" wire:key="dialog-{{ $mode }}-{{ $targetId }}">
                <div class="modal-head">
                    <h2 id="progress-dialog-title" class="min-w-0 flex-1 text-lg font-semibold break-words">{{ $headings[$mode] }}</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>

                <div class="space-y-4 px-5 pt-2">
                    @if ($mode === 'topic')
                        <x-field name="name" label="Name" wire:model="name" maxlength="120" autocomplete="off" hint="Like “Joins” or “Normalisation”." autofocus />
                    @endif
                    @if (($mode === 'topic' && $targetId === null && $modules !== []) || $mode === 'move')
                        <div class="field">
                            <label for="topic-module" class="field-label">Module</label>
                            <select id="topic-module" class="input" wire:model="moduleId" @if ($mode === 'move') autofocus @endif>
                                <option value="">Not in a module</option>
                                @foreach ($modules as $module)
                                    <option value="{{ $module->id }}">{{ $module->title }}</option>
                                @endforeach
                            </select>
                            @error('moduleId') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    @if ($mode === 'question')
                        <div class="field">
                            <label for="question-text" class="field-label">Question</label>
                            <textarea id="question-text" class="input" rows="3" maxlength="1000" wire:model="text" autofocus placeholder="What isn't clear?"></textarea>
                            @error('text') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        @if ($topics !== [])
                            <div class="field">
                                <label for="question-topic" class="field-label">About (optional)</label>
                                <select id="question-topic" class="input" wire:model="topicId">
                                    <option value="">No particular topic</option>
                                    @foreach ($topics as $topic)
                                        <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                                    @endforeach
                                </select>
                                @error('topicId') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    @endif
                </div>

                <div class="modal-actions">
                    <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                    <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">{{ $submit[$mode] }}</x-button>
                </div>
            </form>
        @endif
    </dialog>
</div>
