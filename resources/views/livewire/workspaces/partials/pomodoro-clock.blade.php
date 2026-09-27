{{--
    The Pomodoro clock of an open session (App\Livewire\Workspaces\StudySession),
    as a slim bar: a small ring that fills as the phase runs, the phase and
    its countdown, the rounds, and the controls. resources/js/session.js
    ticks the countdown, fills the ring, and chimes or notifies when a phase
    ends. Its settings are in the session's ⋯ menu.
--}}
@php
    use App\Study\SessionDetails;
    use Illuminate\Support\Str;

    $every = (int) $session->pomodoro['every'];
    $inRound = $session->pomodoros % $every;
    $inRound = $inRound === 0 && $session->pomodoros > 0 && $session->phase === 'long_break' ? $every : $inRound;
    $waiting = $session->waitingForFocus();
    $breakPhase = $session->phase !== 'focus';
    $remaining = $waiting ? (int) $session->pomodoro['focus'] * 60 : $session->phaseRemaining();
    $total = $waiting ? $remaining : max(1, $session->phaseSeconds);
@endphp
<section aria-labelledby="clock-heading" class="clock-bar session-clock"
    x-data="{ sound: $persist(true).as('vistud.pomodoro.sound'), notify: 'Notification' in window ? Notification.permission : 'unsupported' }">
    <h2 id="clock-heading" class="sr-only">Pomodoro clock</h2>
    <div @class(['clock-bar-main', 'is-break' => $breakPhase]) data-ring style="--progress: {{ round(1 - $remaining / $total, 4) }}">
        <svg class="pomodoro-ring" viewBox="0 0 100 100" aria-hidden="true" focusable="false">
            <circle class="pomodoro-track" cx="50" cy="50" r="42" pathLength="100" />
            <circle class="pomodoro-arc" cx="50" cy="50" r="42" pathLength="100" />
        </svg>
        <p @class(['status-chip', 'status-understood' => $session->phase === 'focus' && $session->state === 'running', 'status-covered' => $breakPhase && $session->state === 'break'])>
            <x-icon :name="$waiting ? 'play' : ($breakPhase ? 'coffee' : 'timer')" class="size-3.5" />{{ $session->phaseWords() }}{{ $session->state === 'paused' && ! $waiting ? ' · paused' : '' }}
        </p>
        <p class="session-time tabular-nums">
            <span class="sr-only">{{ SessionDetails::duration($remaining) }} left</span>
            <span aria-hidden="true" data-countdown data-remaining="{{ $remaining }}" data-total="{{ $total }}"
                data-running="{{ $session->phaseRunning() ? '1' : '0' }}" data-drawn="{{ microtime(true) }}"
                data-phase-key="{{ $session->phaseKey() }}"
                data-phase-words="{{ $breakPhase ? 'Break' : 'Focus' }}" data-next="{{ $session->phaseEndWords() }}" data-title
                x-on:vistud-phase-end="$wire.phaseEnded()">{{ SessionDetails::countdown($remaining) }}</span>
        </p>
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-fg-muted">
            <span class="pomodoro-dots" role="img" aria-label="{{ $inRound }} of {{ $every }} pomodoros before the long break">
                @for ($i = 0; $i < $every; $i++)
                    <span @class(['pomodoro-dot', 'is-done' => $i < $inRound])></span>
                @endfor
            </span>
            <span>{{ $session->pomodoros }} {{ Str::plural('pomodoro', $session->pomodoros) }}</span>
            <span aria-hidden="true">·</span>
            <span>studied <span class="tabular-nums" data-clock data-base="{{ $session->studySeconds }}" data-running="{{ $session->state === 'running' ? '1' : '0' }}" data-drawn="{{ microtime(true) }}">{{ gmdate('G:i:s', $session->studySeconds) }}</span></span>
        </p>
    </div>

    <div class="clock-bar-controls">
        @if ($waiting)
            <x-button variant="primary" icon="play" wire:click="resume">Start pomodoro {{ $session->pomodoros + 1 }}</x-button>
        @elseif ($session->state === 'running')
            <x-button icon="pause" wire:click="pause">Pause</x-button>
            <x-button icon="coffee" wire:click="skip">Skip to break</x-button>
        @elseif ($session->state === 'break')
            <x-button variant="primary" icon="play" wire:click="skip">Skip break</x-button>
            <x-button icon="pause" wire:click="pause">Pause</x-button>
        @else
            <x-button variant="primary" icon="play" wire:click="resume">Resume</x-button>
            <x-button icon="{{ $breakPhase ? 'play' : 'coffee' }}" wire:click="skip">{{ $breakPhase ? 'Skip break' : 'Skip to break' }}</x-button>
        @endif
        <x-button icon="square" wire:click="confirmEnd">End session</x-button>
        <button type="button" class="topbar-button" aria-label="Sound" x-on:click="sound = ! sound" x-bind:aria-pressed="sound.toString()">
            <x-icon name="volume-2" class="size-5" x-show="sound" />
            <x-icon name="volume-x" class="size-5" x-show="! sound" x-cloak />
        </button>
        <template x-if="notify === 'default'">
            <button type="button" class="topbar-button" aria-label="Notify me when a phase ends" x-on:click="Notification.requestPermission().then((p) => notify = p)">
                <x-icon name="bell" class="size-5" />
            </button>
        </template>
    </div>
</section>
