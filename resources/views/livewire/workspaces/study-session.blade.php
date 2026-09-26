{{--
    A study session's page (App\Livewire\Workspaces\StudySession): the clock
    and its controls, what happened, the topic, the material and how the AI
    teaches, and the briefing any AI receives.
--}}
@php
    use App\Study\SessionDetails;
    use App\Study\TopicDetails;
    use App\Study\Tutoring;

    $open = $session->isOpen();
    $statusIcons = ['covered' => 'check', 'understood' => 'circle-check', 'confused' => 'circle-alert'];
    $stateIcons = ['running' => 'timer', 'paused' => 'pause', 'break' => 'coffee', 'ended' => 'square'];
    $studied = SessionDetails::duration($session->studySeconds).($session->breakSeconds > 0 ? ', with '.SessionDetails::duration($session->breakSeconds).' of breaks' : '');
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm break-words text-fg-muted">
                <a href="{{ route('workspaces.show', $workspaceId) }}" class="item-link font-normal">Overview</a> · Study session
            </p>
            <h1 class="text-2xl font-semibold tracking-tight break-words sm:text-3xl">{{ $topic?->name ?? 'Study session' }}</h1>
            <p class="text-fg-muted">
                {{ implode(' · ', array_filter([
                    $module?->title,
                    ($session->manual ? 'Logged for ' : 'Started ').$started->format('D j M, H:i'),
                    $ended && ! $session->manual ? 'ended '.$ended->format('H:i') : null,
                ])) }}
            </p>
        </div>
        <div class="flex min-w-0 flex-wrap items-center gap-2">
            <x-button icon="scroll-text" wire:click="showBriefing">Briefing</x-button>
            @include('livewire.workspaces.partials.row-menu', ['id' => 'session-'.$session->id, 'label' => 'this session', 'items' => array_values(array_filter([
                $open ? ['How the AI teaches', 'message-square-text', 'editTeaching', false] : null,
                $open ? [$session->usesPomodoro() ? 'Pomodoro settings' : 'Use the Pomodoro clock', 'timer', 'editPomodoro', false] : null,
                ['Delete session', 'trash-2', 'confirmDelete', false],
            ]))])
        </div>
    </div>

    <div role="status" aria-live="polite">
        @if ($notice)
            <x-alert tone="success" :live="false">{{ $notice }}</x-alert>
        @endif
        @if ($error)
            <x-alert tone="danger" :live="false">{{ $error }}</x-alert>
        @endif
    </div>

    @if ($awaySince)
        <x-alert tone="warning" title="Paused while you were away">
            <p>Nothing happened here after {{ $awaySince }}, so the clock stopped then. If you were studying away from the screen, count that time.</p>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-button icon="check" wire:click="countAway">I was studying: count it</x-button>
                <x-button variant="ghost" icon="play" wire:click="resume">Resume from now</x-button>
            </div>
        </x-alert>
    @elseif ($session->pausedBy === 'long_break')
        <x-alert tone="info">Your break ran past an hour, so the session is paused. Resume when you're back.</x-alert>
    @endif

    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
        <div class="min-w-0 space-y-4 lg:col-span-2">
            @if ($session->usesPomodoro() && $open)
                @include('livewire.workspaces.partials.pomodoro-clock')
            @else
                <section aria-labelledby="clock-heading" class="overview-card session-clock">
                    <h2 id="clock-heading" class="sr-only">Clock</h2>
                    <p @class(['status-chip', 'status-understood' => $session->state === 'running', 'status-covered' => $session->state === 'break'])>
                        <x-icon :name="$stateIcons[$session->state]" class="size-3.5" />{{ $session->stateWords() }}
                    </p>
                    <p class="session-time tabular-nums">
                        <span class="sr-only">Study time: {{ SessionDetails::duration($session->studySeconds) }}</span>
                        <span aria-hidden="true" data-clock data-base="{{ $session->studySeconds }}" data-running="{{ $session->state === 'running' ? '1' : '0' }}" data-drawn="{{ microtime(true) }}">{{ gmdate('G:i:s', $session->studySeconds) }}</span>
                    </p>
                    <p class="text-fg-muted">
                        Study time
                        @if ($session->state === 'break')
                            · break <span class="tabular-nums" data-clock data-base="{{ $session->breakSeconds }}" data-running="1" data-drawn="{{ microtime(true) }}">{{ gmdate('G:i:s', $session->breakSeconds) }}</span>
                        @elseif ($session->breakSeconds > 0)
                            · breaks {{ SessionDetails::duration($session->breakSeconds) }}
                        @endif
                    </p>
                    @if ($open)
                        <div class="flex flex-wrap justify-center gap-2 pt-2">
                            @if ($session->state === 'running')
                                <x-button icon="pause" wire:click="pause">Pause</x-button>
                                <x-button icon="coffee" wire:click="takeBreak">Take a break</x-button>
                            @elseif ($session->state === 'paused')
                                <x-button variant="primary" icon="play" wire:click="resume">Resume</x-button>
                                <x-button icon="coffee" wire:click="takeBreak">Take a break</x-button>
                            @else
                                <x-button variant="primary" icon="play" wire:click="resume">Back to studying</x-button>
                                <x-button icon="pause" wire:click="pause">Pause</x-button>
                            @endif
                            <x-button :variant="$session->state === 'running' ? 'primary' : 'secondary'" icon="square" wire:click="confirmEnd">End session</x-button>
                        </div>
                    @endif
                </section>
            @endif

            <section aria-labelledby="timeline-heading" class="overview-card space-y-3">
                <h2 id="timeline-heading" class="font-semibold">What happened</h2>
                <ol class="divide-y divide-divider" role="list">
                    @foreach ($timeline as $row)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                            <x-icon :name="['study' => 'timer', 'break' => 'coffee', 'pause' => 'pause'][$row['kind']]" class="size-4 shrink-0 text-fg-muted" />
                            <span class="min-w-0 flex-1">{{ $row['words'] }}</span>
                            <span class="text-sm text-fg-muted tabular-nums">{{ $row['from'] }}–{{ $row['to'] ?? 'now' }}</span>
                            @if ($row['seconds'] !== null)
                                <span class="w-24 text-right text-sm font-medium tabular-nums">{{ SessionDetails::duration($row['seconds']) }}</span>
                            @else
                                <span class="w-24" aria-hidden="true"></span>
                            @endif
                        </li>
                    @endforeach
                </ol>
                @unless ($open)
                    <p class="border-t border-divider pt-3 font-medium">Studied {{ $studied }}</p>
                @endunless
            </section>
        </div>

        <div class="min-w-0 space-y-4">
            @if ($topic)
                <section aria-labelledby="topic-heading" class="overview-card space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="topic-heading" class="font-semibold">{{ $topic->name }}</h2>
                        <a href="{{ route('workspaces.show', [$workspaceId, 'progress']) }}" class="item-link text-sm">Open Progress</a>
                    </div>
                    <p class="text-sm text-fg-muted">Evidence: {{ $topic->evidence() }}</p>
                    <div class="segmented segmented-stack" role="group" aria-label="Status of {{ $topic->name }}">
                        @foreach (['covered' => 'Covered', 'understood' => 'Understood', 'confused' => 'Confused'] as $status => $word)
                            <button type="button" @class(['segmented-option', 'is-current' => $topic->status === $status]) wire:click="report('{{ $status }}')" aria-pressed="{{ $topic->status === $status ? 'true' : 'false' }}">
                                <x-icon :name="$statusIcons[$status]" class="size-4" />{{ $word }}
                            </button>
                        @endforeach
                    </div>
                </section>
            @endif

            <section aria-labelledby="material-heading" class="overview-card space-y-3">
                <div class="space-y-1">
                    <h2 id="material-heading" class="font-semibold">Material{{ $module ? ' · '.$module->title : '' }}</h2>
                    @if ($open)
                        <p class="text-sm text-fg-muted">What you use goes into the briefing: a note's text, and a file's name for you to share with the AI.</p>
                    @endif
                </div>
                @if ($material !== [])
                    <ul class="space-y-2" role="list">
                        @foreach ($material as $item)
                            @include('livewire.workspaces.partials.material-row')
                        @endforeach
                    </ul>
                @elseif ($module)
                    <p class="text-sm text-fg-muted">No notes or files in {{ $module->title }} yet.</p>
                @else
                    <p class="text-sm text-fg-muted">No module for this session: use notes and files from anywhere in the course below.</p>
                @endif
                @if ($open && $otherMaterial !== [])
                    <div x-data="{ more: {{ $material === [] && ! $module ? 'true' : 'false' }} }">
                        <button type="button" class="disclosure" x-on:click="more = ! more" x-bind:aria-expanded="more.toString()" aria-controls="other-material">
                            <x-icon name="chevron-right" class="size-4 transition-transform" x-bind:class="more && 'rotate-90'" />Other notes and files ({{ count($otherMaterial) }})
                        </button>
                        <ul id="other-material" x-show="more" x-cloak class="mt-2 space-y-2" role="list">
                            @foreach ($otherMaterial as $item)
                                @include('livewire.workspaces.partials.material-row')
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>

            <section aria-labelledby="teaching-heading" class="overview-card space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="teaching-heading" class="font-semibold">How the AI teaches</h2>
                    @if ($open)
                        <x-button variant="ghost" icon="pencil" wire:click="editTeaching" aria-label="Change how the AI teaches">Change</x-button>
                    @endif
                </div>
                <dl class="space-y-2">
                    @foreach (Tutoring::QUESTIONS as $key => $question)
                        <div class="flex flex-wrap justify-between gap-x-3 text-sm">
                            <dt class="text-fg-muted">{{ $question }}</dt>
                            <dd class="font-medium">{{ Tutoring::CHOICES[$key][$session->tutoring[$key]][0] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </div>
    </div>

    <dialog id="session-dialog" class="modal" aria-labelledby="session-dialog-title"
        wire:ignore.self
        x-data
        x-on:session-dialog-open.window="$el.open || $el.showModal()"
        x-on:session-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.mode && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($mode)
            <form wire:submit="save" novalidate @class(['modal-panel', 'modal-panel-wide' => $mode === 'briefing']) wire:key="session-dialog-{{ $mode }}" @if ($mode === 'briefing') x-data="{ copied: false }" @endif>
                <div class="modal-head">
                    <h2 id="session-dialog-title" class="min-w-0 flex-1 text-lg font-semibold" tabindex="-1" autofocus>{{ ['end' => 'End this session?', 'delete' => 'Delete this session?', 'pomodoro' => 'Session clock', 'teaching' => 'How the AI teaches', 'briefing' => 'Briefing for the AI'][$mode] }}</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>
                <div class="space-y-4 px-5 pt-2">
                    @if ($mode === 'briefing')
                        <p class="text-sm text-fg-muted">What any AI receives when this session starts: the tutoring prompt, then where you are. Paste it into an AI you already use, or download it and attach it. About {{ number_format($briefing->tokens()) }} tokens.</p>
                        @if ($briefing->trimmed)
                            <x-alert tone="info" :live="false">Some things were left out to keep it short. The most relevant come first.</x-alert>
                        @endif
                        <pre class="briefing-text" tabindex="0" aria-label="Briefing text" x-ref="text">{{ $briefing->markdown }}</pre>
                        <p class="sr-only" role="status" x-text="copied ? 'Copied to the clipboard.' : ''"></p>
                    @elseif ($mode === 'teaching')
                        @include('livewire.workspaces.partials.teaching-fields')
                        <p class="text-sm text-fg-muted">The briefing asks for this from now on. An AI you already briefed needs the new briefing, or to be told.</p>
                    @elseif ($mode === 'pomodoro')
                        @include('livewire.workspaces.partials.pomodoro-fields')
                        <p class="text-sm text-fg-muted">{{ $session->usesPomodoro() ? 'Time already studied stays, and the current phase keeps its progress with the new lengths.' : 'Time already studied stays. The first focus period starts counting now.' }}</p>
                    @elseif ($mode === 'end')
                        <p>You studied {{ $studied }}.</p>
                        @if ($topic)
                            <fieldset class="space-y-2">
                                <legend class="field-label mb-1">How is {{ $topic->name }} now?</legend>
                                @foreach (['' => 'Leave it as it is', 'covered' => 'Covered', 'understood' => 'Understood', 'confused' => 'Still confusing'] as $value => $word)
                                    <label class="move-option">
                                        <input type="radio" name="topic-status" value="{{ $value }}" wire:model="topicStatus">
                                        <span>{{ $word }}</span>
                                    </label>
                                @endforeach
                            </fieldset>
                        @endif
                    @else
                        <p class="text-fg-muted">Its {{ SessionDetails::duration($session->studySeconds) }} of study time comes off your totals. What you recorded during it stays. This can't be undone.</p>
                    @endif
                </div>
                <div class="modal-actions">
                    @if ($mode === 'briefing')
                        <x-button x-on:click="$el.closest('dialog').close()">Close</x-button>
                        <a href="{{ route('workspaces.sessions.briefing', [$workspaceId, $session->id]) }}" class="btn btn-secondary"><x-icon name="download" class="size-4" />Download</a>
                        <x-button variant="primary" icon="copy" x-on:click="
                            const text = $refs.text.textContent;
                            const done = () => { copied = true; setTimeout(() => copied = false, 2500); };
                            const select = () => {
                                const range = document.createRange();
                                range.selectNodeContents($refs.text);
                                const selection = window.getSelection();
                                selection.removeAllRanges();
                                selection.addRange(range);
                                try { if (document.execCommand('copy')) done(); } catch (e) {}
                            };
                            navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText(text).then(done, select) : select();
                        "><span x-show="! copied">Copy</span><span x-show="copied" x-cloak>Copied</span></x-button>
                    @else
                        <x-button x-on:click="$el.closest('dialog').close()">{{ $mode === 'end' ? 'Keep studying' : 'Cancel' }}</x-button>
                        <x-button type="submit" :variant="$mode === 'delete' ? 'danger' : 'primary'" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">{{ ['end' => 'End session', 'delete' => 'Delete', 'pomodoro' => 'Save', 'teaching' => 'Save'][$mode] }}</x-button>
                    @endif
                </div>
            </form>
        @endif
    </dialog>
</div>
