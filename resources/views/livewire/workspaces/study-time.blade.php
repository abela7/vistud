{{--
    Study time on a workspace's Overview (App\Livewire\Workspaces\StudyTime):
    totals, the latest sessions, and the start and log dialogs.
--}}
@php
    use App\Study\SessionDetails;
    use Illuminate\Support\Carbon;
@endphp
<section aria-labelledby="study-time-heading" class="overview-card space-y-4">
    <h2 id="study-time-heading" class="font-semibold">Study time</h2>

    <div role="status" aria-live="polite">
        @if ($notice)
            <x-alert tone="success" :live="false">{{ $notice }}</x-alert>
        @endif
    </div>

    <dl class="grid grid-cols-2 gap-3">
        <div>
            <dt class="text-sm text-fg-muted">This week</dt>
            <dd class="text-xl font-semibold">{{ SessionDetails::duration($totals['since']) }}</dd>
        </div>
        <div>
            <dt class="text-sm text-fg-muted">In all</dt>
            <dd class="text-xl font-semibold">{{ SessionDetails::duration($totals['all']) }}</dd>
        </div>
        @if ($totals['pomodoros'] > 0)
            <div>
                <dt class="text-sm text-fg-muted">Pomodoros this week</dt>
                <dd class="text-xl font-semibold">{{ $totals['pomodoros_since'] }}</dd>
            </div>
            <div>
                <dt class="text-sm text-fg-muted">Pomodoros in all</dt>
                <dd class="text-xl font-semibold">{{ $totals['pomodoros'] }}</dd>
            </div>
        @endif
    </dl>

    @if ($openWorkspace)
        <p class="text-sm text-fg-muted">A session is open in {{ $openWorkspace->name }}. End it to start one here.</p>
    @endif
    <div class="grid gap-2">
        @if ($open)
            <a href="{{ route('workspaces.sessions.show', [$open->workspaceId, $open->id]) }}" @class(['btn', 'btn-primary' => ! $openWorkspace, 'btn-secondary' => $openWorkspace])>
                <x-icon name="timer" class="size-4" />{{ $openWorkspace ? 'Go to that session' : 'Back to your session' }}
            </a>
        @else
            <x-button variant="primary" icon="play" wire:click="newSession">Start studying</x-button>
        @endif
        <x-button icon="history" wire:click="logTime">Log time</x-button>
    </div>

    @if ($recent !== [])
        <div class="space-y-1.5">
            <h3 class="text-sm font-semibold">Latest sessions</h3>
            <ul class="space-y-2" role="list">
                @foreach ($recent as $session)
                    <li class="flex items-start gap-2">
                        <x-icon :name="$session->manual ? 'history' : 'timer'" class="mt-0.5 size-4 shrink-0 text-fg-muted" />
                        <span class="min-w-0 flex-1">
                            <a href="{{ route('workspaces.sessions.show', [$session->workspaceId, $session->id]) }}" class="item-link">{{ $session->topicId !== null ? ($topicNames[$session->topicId] ?? 'A removed topic') : 'Study session' }}</a>
                            <span class="block text-sm text-fg-muted">{{ Carbon::parse($session->startedAt)->setTimezone($zone)->format('D j M, H:i') }}</span>
                        </span>
                        <span class="shrink-0 text-sm font-medium">{{ SessionDetails::duration($session->studySeconds) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <dialog id="study-dialog" class="modal" aria-labelledby="study-dialog-title"
        wire:ignore.self
        x-data
        x-on:study-dialog-open.window="$el.open || $el.showModal()"
        x-on:study-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.mode && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($mode)
            <form wire:submit="save" novalidate class="modal-panel" wire:key="study-dialog-{{ $mode }}">
                <div class="modal-head">
                    <h2 id="study-dialog-title" class="min-w-0 flex-1 text-lg font-semibold">{{ $mode === 'start' ? 'Start studying' : 'Log time you studied' }}</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>
                <div class="space-y-4 px-5 pt-2">
                    @if ($mode === 'log')
                        <p class="text-sm text-fg-muted">For time you studied without the clock, like a library afternoon.</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field name="date" label="Date" type="date" wire:model="date" />
                            <x-field name="time" label="Started at" type="time" wire:model="time" />
                        </div>
                        <x-field name="minutes" label="Minutes" type="number" inputmode="numeric" min="1" max="720" wire:model="minutes" autofocus hint="Like 45, or 90 for an hour and a half." />
                    @endif
                    <div class="field">
                        <label for="study-topic" class="field-label">{{ $mode === 'start' ? 'What are you studying? (optional)' : 'Topic (optional)' }}</label>
                        <select id="study-topic" class="input" wire:model="topicId" @if ($mode === 'start') autofocus @endif>
                            <option value="">No particular topic</option>
                            @foreach ($topics as $topic)
                                <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                            @endforeach
                        </select>
                        @error('topicId') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    @if ($mode === 'start' && $modules !== [])
                        <div class="field">
                            <label for="study-module" class="field-label">Module (optional)</label>
                            <select id="study-module" class="input" wire:model="moduleId" aria-describedby="study-module-hint">
                                <option value="">The topic's, or none</option>
                                @foreach ($modules as $module)
                                    <option value="{{ $module->id }}">{{ $module->title }}</option>
                                @endforeach
                            </select>
                            <p id="study-module-hint" class="field-hint">Its notes and files show beside the clock.</p>
                        </div>
                    @endif
                    @if ($mode === 'start')
                        @include('livewire.workspaces.partials.pomodoro-fields')
                        <p class="text-sm text-fg-muted">The clock starts now. Pause it whenever you stop.</p>
                    @endif
                </div>
                <div class="modal-actions">
                    <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                    <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">{{ $mode === 'start' ? 'Start' : 'Log time' }}</x-button>
                </div>
            </form>
        @endif
    </dialog>
</section>
