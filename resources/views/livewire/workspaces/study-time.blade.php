{{--
    Starting a study session and logging time (App\Livewire\Workspaces\StudyTime);
    on the Overview ($stats), the tiles: the streak, the last 7 days, and the
    flashcards to review.
--}}
@php
    use App\Study\SessionDetails;
    use Carbon\CarbonImmutable;
@endphp
<div @class(['contents' => ! $stats])>
    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" />
    </div>

    @if ($stats)
        @php
            $days = $rhythm['days'];
            $total = array_sum($days);
            $most = max(1, max($days));
            $streak = $rhythm['streak'];
        @endphp
        <div class="stat-row">
            <div class="stat-tile">
                <span @class(['stat-icon', 'is-lit' => $streak > 0]) aria-hidden="true"><x-icon name="flame" class="size-5" /></span>
                <div class="min-w-0">
                    <p class="stat-label">Streak</p>
                    <p class="stat-value">{{ $streak === 1 ? '1 day' : $streak.' days' }}</p>
                </div>
            </div>

            <div class="stat-tile">
                <div class="min-w-0 flex-1">
                    <p class="stat-label">Last 7 days</p>
                    <p class="stat-value">{{ SessionDetails::duration($total) }}</p>
                </div>
                <ol class="week-bars" role="list" aria-label="Study time by day">
                    @foreach ($days as $date => $seconds)
                        @php $day = CarbonImmutable::parse($date); @endphp
                        <li @class(['is-today' => $date === $today]) title="{{ $day->format('D j M') }}: {{ SessionDetails::duration($seconds) }}">
                            <span class="week-bar" style="--h: {{ $seconds > 0 ? max(12, round($seconds / $most * 100)) : 0 }}%"></span>
                            <span class="week-day" aria-hidden="true">{{ $day->format('D')[0] }}</span>
                            <span class="sr-only">{{ $day->format('l') }}: {{ SessionDetails::duration($seconds) }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>

            <a href="{{ $cards['due'] > 0 ? route('workspaces.flashcards.review', $workspaceId) : route('workspaces.show', [$workspaceId, 'flashcards']) }}" class="stat-tile is-link">
                <span class="stat-icon" aria-hidden="true"><x-icon name="gallery-vertical-end" class="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <p class="stat-label">Cards to review</p>
                    <p class="stat-value">{{ $cards['due'] }}</p>
                </div>
                <x-icon name="chevron-right" class="size-5 shrink-0 text-fg-subtle" />
            </a>
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
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field name="date" label="Date" type="date" wire:model="date" />
                            <x-field name="time" label="Started at" type="time" wire:model="time" />
                        </div>
                        <x-field name="minutes" label="Minutes" type="number" inputmode="numeric" min="1" max="720" wire:model="minutes" autofocus />
                    @endif
                    <div class="field">
                        <label for="study-topic" class="field-label">Topic (optional)</label>
                        <select id="study-topic" class="input" wire:model="topicId" @if ($mode === 'start') autofocus @endif>
                            <option value="">Any</option>
                            @foreach ($topics as $topic)
                                <option value="{{ $topic->id }}">{{ $topic->name }}</option>
                            @endforeach
                        </select>
                        @error('topicId') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    @if ($mode === 'start' && $modules !== [])
                        <div class="field">
                            <label for="study-module" class="field-label">Module (optional)</label>
                            <select id="study-module" class="input" wire:model="moduleId">
                                <option value="">None</option>
                                @foreach ($modules as $module)
                                    <option value="{{ $module->id }}">{{ $module->title }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    @if ($mode === 'start')
                        <div x-data="{ open: false }" class="space-y-3">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                <p class="field-label">How the AI teaches</p>
                                <button type="button" class="text-link text-sm" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-controls="start-teaching">Change</button>
                            </div>
                            <p class="text-sm text-fg-muted" x-show="! open">{{ \App\Study\Tutoring::summary(['method' => $method, 'check_ins' => $checkIns, 'quiz' => $quiz, 'pace' => $pace]) }}</p>
                            <div id="start-teaching" x-show="open" x-cloak>
                                @include('livewire.workspaces.partials.teaching-fields')
                            </div>
                        </div>
                        @include('livewire.workspaces.partials.pomodoro-fields')
                    @endif
                </div>
                <div class="modal-actions">
                    <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                    <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">{{ $mode === 'start' ? 'Start' : 'Log time' }}</x-button>
                </div>
            </form>
        @endif
    </dialog>
</div>
