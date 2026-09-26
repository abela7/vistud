{{--
    The open study session in the top bar (App\Livewire\Study\SessionBar):
    a link to it with its clock, and pause or resume. Empty without one.
--}}
<div class="session-bar">
    @if ($session)
        @php
            $running = $session->state === 'running';
            $url = route('workspaces.sessions.show', [$session->workspaceId, $session->id]);
            $words = match ($session->state) { 'running' => 'studying', 'break' => 'on a break', default => 'paused' };
        @endphp
        <div @class(['session-pill', "is-{$session->state}"]) @if ($running) data-session-heartbeat x-data x-on:vistud-heartbeat="$wire.heartbeat()" @endif>
            <a href="{{ $url }}" class="session-pill-link">
                <span class="session-dot max-sm:hidden" aria-hidden="true"></span>
                <span class="max-md:sr-only">{{ $workspace->name }}</span>
                <span class="sr-only">: {{ $words }}, {{ \App\Study\SessionDetails::duration($session->studySeconds) }} studied. Open the session.</span>
                <span class="tabular-nums" aria-hidden="true" data-clock data-base="{{ $session->studySeconds }}" data-running="{{ $running ? '1' : '0' }}" data-drawn="{{ microtime(true) }}">{{ gmdate('G:i:s', $session->studySeconds) }}</span>
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
        </div>
    @endif
</div>
