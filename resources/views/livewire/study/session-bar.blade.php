{{--
    The open study session in the top bar (App\Livewire\Study\SessionBar):
    a link to it with its clock, pause or resume, and Hide the timer (the
    owner's review, 2026-09-27: watching the time can be too much). Hidden,
    it's a small pulsing dot that brings it back; the choice is remembered
    on this device. Empty without a session.
--}}
<div class="session-bar" x-data="{ hidden: $persist(false).as('vistud.timer.hidden') }">
    @if ($session)
        @php
            $running = $session->state === 'running';
            $url = route('workspaces.sessions.show', [$session->workspaceId, $session->id]);
            $words = match ($session->state) { 'running' => 'studying', 'break' => 'on a break', default => 'paused' };
        @endphp
        @php $pomodoro = $session->usesPomodoro() && ! $session->waitingForFocus(); @endphp
        <button type="button" @class(['session-mini', "is-{$session->state}"]) x-show="hidden" x-cloak x-on:click="hidden = false" aria-label="Show the study timer" title="Show the study timer">
            <span class="session-dot" aria-hidden="true"></span>
        </button>
        <div @class(['session-pill', "is-{$session->state}"]) x-show="! hidden" x-on:vistud-heartbeat="$wire.heartbeat()" x-on:vistud-phase-end="$wire.$refresh()" @if ($running) data-session-heartbeat @endif>
            <a href="{{ $url }}" class="session-pill-link">
                @if ($pomodoro)
                    <x-icon :name="$session->phase === 'focus' ? 'timer' : 'coffee'" class="size-4" />
                @else
                    <span class="session-dot max-sm:hidden" aria-hidden="true"></span>
                @endif
                <span class="max-md:sr-only">{{ $workspace->name }}</span>
                @if ($pomodoro)
                    <span class="sr-only">: {{ $session->phaseWords() }}, {{ \App\Study\SessionDetails::duration($session->phaseRemaining()) }} left. Open the session.</span>
                    <span class="tabular-nums" aria-hidden="true" data-countdown data-remaining="{{ $session->phaseRemaining() }}" data-total="{{ max(1, $session->phaseSeconds) }}" data-running="{{ $session->phaseRunning() ? '1' : '0' }}" data-drawn="{{ microtime(true) }}" data-phase-key="{{ $session->phaseKey() }}" data-next="{{ $session->phaseEndWords() }}" data-phase-words="{{ $session->phase === 'focus' ? 'Focus' : 'Break' }}">{{ \App\Study\SessionDetails::countdown($session->phaseRemaining()) }}</span>
                @else
                    <span class="sr-only">: {{ $session->waitingForFocus() ? 'ready for the next pomodoro' : $words }}, {{ \App\Study\SessionDetails::duration($session->studySeconds) }} studied. Open the session.</span>
                    <span class="tabular-nums" aria-hidden="true" data-clock data-base="{{ $session->studySeconds }}" data-running="{{ $running ? '1' : '0' }}" data-drawn="{{ microtime(true) }}">{{ gmdate('G:i:s', $session->studySeconds) }}</span>
                @endif
            </a>
            @if ($running)
                <button type="button" class="topbar-button" wire:click="pause" aria-label="Pause the session" title="Pause">
                    <x-icon name="pause" class="size-5" />
                </button>
            @else
                <button type="button" class="topbar-button" wire:click="resume" aria-label="{{ $session->state === 'break' ? 'End the break and study' : 'Resume the session' }}" title="Resume">
                    <x-icon name="play" class="size-5" />
                </button>
            @endif
            <button type="button" class="topbar-button" x-on:click="hidden = true" aria-label="Hide the timer" title="Hide the timer">
                <x-icon name="minus" class="size-5" />
            </button>
        </div>
    @endif
</div>
