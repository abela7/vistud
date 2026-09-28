{{--
    A module's study sessions on their own page (App\Livewire\Workspaces\ModuleSessions):
    summary totals, search, filter by status, sort, and the full list of sessions.
--}}
@php
    use App\Study\SessionDetails;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Str;

    $uid = $this->getId();
@endphp
<div class="space-y-6">
    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" />
    </div>

    @if ($counts['all'] > 0)
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-4">
            <div>
                <p class="text-sm font-medium text-fg-muted">
                    {{ $counts['all'] }} {{ Str::plural('session', $counts['all']) }} · {{ $totalDuration }} studied
                </p>
            </div>
            <x-button variant="primary" icon="play" wire:click="startSession">Study this module</x-button>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="search-field">
                <x-icon name="search" class="size-4" />
                <label for="session-search-{{ $uid }}" class="sr-only">Search sessions</label>
                <input id="session-search-{{ $uid }}" type="search" class="input" placeholder="Search sessions" wire:model.live.debounce.250ms="search" autocomplete="off">
            </div>

            <div class="inline-flex flex-wrap items-center gap-3">
                <div class="segmented segmented-sm" role="group" aria-label="Filter study sessions">
                    <button type="button" @class(['segmented-option', 'is-current' => $filter === 'all']) wire:click="show('all')" aria-pressed="{{ $filter === 'all' ? 'true' : 'false' }}">
                        All <span class="tab-count">{{ $counts['all'] }}</span>
                    </button>
                    @if ($counts['running'] > 0)
                        <button type="button" @class(['segmented-option', 'is-current' => $filter === 'running']) wire:click="show('running')" aria-pressed="{{ $filter === 'running' ? 'true' : 'false' }}">
                            Studying <span class="tab-count">{{ $counts['running'] }}</span>
                        </button>
                    @endif
                    <button type="button" @class(['segmented-option', 'is-current' => $filter === 'ended']) wire:click="show('ended')" aria-pressed="{{ $filter === 'ended' ? 'true' : 'false' }}">
                        Ended <span class="tab-count">{{ $counts['ended'] }}</span>
                    </button>
                </div>

                <div class="flex items-center gap-2">
                    <label for="session-sort-{{ $uid }}" class="text-sm text-fg-muted">Sort</label>
                    <select id="session-sort-{{ $uid }}" class="input input-sm" wire:model.live="sort">
                        <option value="newest">Newest first</option>
                        <option value="oldest">Oldest first</option>
                        <option value="longest">Longest first</option>
                    </select>
                </div>
            </div>
        </div>

        @if ($shown !== [])
            <ul class="item-list" role="list" aria-label="Study sessions">
                @foreach ($shown as $session)
                    <li class="item-row" wire:key="session-{{ $session->id }}">
                        <span class="item-icon ws-colour-green" aria-hidden="true">
                            <x-icon :name="$session->isOpen() ? 'timer' : 'history'" class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1 space-y-0.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('workspaces.sessions.show', [$workspaceId, $session->id]) }}" class="tile-link font-medium">
                                    {{ $session->topicId !== null ? ($topicNames[$session->topicId] ?? 'Study session') : 'Study session' }}
                                </a>
                                @if ($session->isOpen())
                                    <span class="badge badge-success">Studying now</span>
                                @endif
                                @if ($session->usesPomodoro())
                                    <span class="badge badge-neutral"><x-icon name="timer" class="size-3.5" />Pomodoro</span>
                                @endif
                            </div>
                            <p class="item-meta">
                                {{ $session->isOpen() ? 'Studying now' : SessionDetails::duration($session->studySeconds).' studied'.($session->breakSeconds > 0 ? ', '.SessionDetails::duration($session->breakSeconds).' of breaks' : '') }} · {{ Carbon::parse($session->startedAt)->diffForHumans() }}
                            </p>
                        </div>
                        <div class="shrink-0">
                            <a href="{{ route('workspaces.sessions.show', [$workspaceId, $session->id]) }}" class="btn btn-ghost btn-sm" aria-label="Open session">
                                <x-icon name="chevron-right" class="size-4" />
                            </a>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-sm text-fg-muted">{{ trim($search) !== '' ? 'No study session matches.' : 'No sessions in this filter.' }}</p>
        @endif
    @else
        <div class="card p-8 text-center space-y-3">
            <span class="mx-auto flex size-12 items-center justify-center rounded-full bg-surface-sunken text-fg-muted" aria-hidden="true">
                <x-icon name="history" class="size-6" />
            </span>
            <div class="space-y-1">
                <h2 class="text-base font-semibold">No study sessions yet</h2>
                <p class="text-sm text-fg-muted max-w-md mx-auto">Start a study session in this module to track your focus, take breaks, and record notes and flashcards.</p>
            </div>
            <div class="pt-2">
                <x-button variant="primary" icon="play" wire:click="startSession">Start studying</x-button>
            </div>
        </div>
    @endif
</div>
