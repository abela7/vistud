{{--
    The Pomodoro clock of an open session (App\Livewire\Workspaces\StudySession):
    the phase, its countdown in a ring, the rounds, and the controls.
    resources/js/session.js ticks the countdown, fills the ring, and chimes
    or notifies when a phase ends.
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
<section aria-labelledby="clock-heading" class="overview-card session-clock"
    x-data="{ sound: $persist(true).as('vistud.pomodoro.sound'), notify: 'Notification' in window ? Notification.permission : 'unsupported' }">
    <h2 id="clock-heading" class="sr-only">Pomodoro clock</h2>
    <p @class(['status-chip', 'status-understood' => $session->phase === 'focus' && $session->state === 'running', 'status-covered' => $breakPhase && $session->state === 'break'])>
        <x-icon :name="$waiting ? 'play' : ($breakPhase ? 'coffee' : 'timer')" class="size-3.5" />{{ $session->phaseWords() }}{{ $session->state === 'paused' && ! $waiting ? ' · paused' : '' }}
    </p>

    <div @class(['pomodoro-ring', 'is-break' => $breakPhase]) data-ring style="--progress: {{ round(1 - $remaining / $total, 4) }}">
        <svg viewBox="0 0 100 100" aria-hidden="true" focusable="false">
            <circle class="pomodoro-track" cx="50" cy="50" r="46" pathLength="100" />
            <circle class="pomodoro-arc" cx="50" cy="50" r="46" pathLength="100" />
        </svg>
        <p class="pomodoro-time tabular-nums">
            <span class="sr-only">{{ SessionDetails::duration($remaining) }} left</span>
            <span aria-hidden="true" data-countdown data-remaining="{{ $remaining }}" data-total="{{ $total }}"
                data-running="{{ $session->phaseRunning() ? '1' : '0' }}" data-drawn="{{ microtime(true) }}"
                data-phase-key="{{ $session->phaseKey() }}"
                data-phase-words="{{ $breakPhase ? 'Break' : 'Focus' }}" data-next="{{ $session->phaseEndWords() }}" data-title
                x-on:vistud-phase-end="$wire.phaseEnded()">{{ SessionDetails::countdown($remaining) }}</span>
        </p>
    </div>

    <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-fg-muted">
        <span class="pomodoro-dots" role="img" aria-label="{{ $inRound }} of {{ $every }} pomodoros before the long break">
            @for ($i = 0; $i < $every; $i++)
                <span @class(['pomodoro-dot', 'is-done' => $i < $inRound])></span>
            @endfor
        </span>
        <span>{{ $session->pomodoros }} {{ Str::plural('pomodoro', $session->pomodoros) }}</span>
        <span aria-hidden="true">·</span>
        <span>Study time <span class="tabular-nums" data-clock data-base="{{ $session->studySeconds }}" data-running="{{ $session->state === 'running' ? '1' : '0' }}" data-drawn="{{ microtime(true) }}">{{ gmdate('G:i:s', $session->studySeconds) }}</span></span>
    </div>

    <div class="flex flex-wrap justify-center gap-2 pt-2">
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
        <x-button :variant="$session->state === 'running' ? 'primary' : 'secondary'" icon="square" wire:click="confirmEnd">End session</x-button>
    </div>

    <div class="flex flex-wrap items-center justify-center gap-x-2 gap-y-1 border-t border-divider pt-3 text-sm text-fg-muted">
        <span>{{ $session->pomodoroWords() }}{{ $session->pomodoro['auto'] ? '' : '; you start each focus' }}</span>
        <button type="button" class="item-link text-sm" wire:click="editPomodoro" aria-label="Change the Pomodoro settings">Change</button>
        <span aria-hidden="true">·</span>
        <button type="button" class="item-link text-sm" x-on:click="sound = ! sound" x-bind:aria-pressed="sound.toString()">Sound</button>
        <template x-if="notify === 'default'">
            <span class="contents">
                <span aria-hidden="true">·</span>
                <button type="button" class="item-link text-sm" x-on:click="Notification.requestPermission().then((p) => notify = p)">Notify me when a phase ends</button>
            </span>
        </template>
    </div>
</section>
