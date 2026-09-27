{{--
    A study session's page (App\Livewire\Workspaces\StudySession): an open
    space to study in (the owner's review, 2026-09-27). A slim clock bar,
    then what to do next as tiles: study with an AI, save from the chat, ask
    a question, write a card or a note, the notes and files. Questions, the
    tutor's summary and, once it has ended, what happened show only when
    there are any. The notes and files, the briefing and the settings open
    in the side panel.
--}}
@php
    use App\Study\SessionDetails;
    use App\Study\Topics;
    use Illuminate\Support\Str;

    $open = $session->isOpen();
    $statusIcons = ['covered' => 'check', 'understood' => 'circle-check', 'confused' => 'circle-alert'];
    $stateIcons = ['running' => 'timer', 'paused' => 'pause', 'break' => 'coffee', 'ended' => 'square'];
    $studied = SessionDetails::duration($session->studySeconds).($session->breakSeconds > 0 ? ', with '.SessionDetails::duration($session->breakSeconds).' of breaks' : '');
    $usedCount = count(array_filter([...$material, ...$otherMaterial], fn ($item) => $session->uses($item['key'])));
    $title = $topic?->name ?? $module?->title ?? 'Study session';
    $uid = $this->getId();
    $tiles = array_values(array_filter([
        $open ? ['ai', 'Study with an AI', 'sparkles', 'purple', 'Copy the briefing into any AI', ['wire:click' => 'showBriefing']] : null,
        ['chat', 'Save from the chat', 'clipboard-paste', 'green', 'Keep what the AI taught you', ['x-on:click' => "Livewire.dispatch('capture-open')"]],
        ['question', 'Ask a question', 'circle-help', 'blue', $openQuestions > 0 ? $openQuestions.' '.Str::plural('question', $openQuestions).' open' : 'Something you don\'t get', ['x-on:click' => "Livewire.dispatch('question-new')"]],
        ['card', 'New flashcard', 'gallery-vertical-end', 'orange', 'To review later', ['wire:click' => 'newFlashcard']],
        ['note', 'Write a note', 'file-plus', 'teal', $module ? 'In '.$module->title : 'In Notes & files', ['wire:click' => 'newNote']],
        ['material', 'Notes & files', 'folder-open', 'amber', $usedCount > 0 ? $usedCount.' for the AI' : ($material !== [] ? count($material).' in '.($module?->title ?? 'this session') : 'Choose what the AI gets'), ['wire:click' => 'openMaterial']],
    ]));
@endphp
<div class="space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <nav aria-label="Path" class="crumbs">
                <ol role="list">
                    @if ($module)
                        <li><a href="{{ route('workspaces.show', [$workspaceId, 'modules']) }}">Modules</a></li>
                        <li><a href="{{ route('workspaces.modules.show', [$workspaceId, $module->id]) }}">{{ $module->title }}</a></li>
                    @else
                        <li><a href="{{ route('workspaces.show', $workspaceId) }}">Overview</a></li>
                    @endif
                    <li>Study session</li>
                </ol>
            </nav>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h1 class="text-2xl font-semibold tracking-tight break-words sm:text-3xl">{{ $title }}</h1>
                @if ($topic && in_array($topic->status, Topics::STATUSES, true))
                    <span class="status-chip status-{{ $topic->status }}"><x-icon :name="$statusIcons[$topic->status]" class="size-3.5" />{{ Str::ucfirst($topic->status) }}</span>
                @endif
            </div>
            <p class="text-fg-muted">
                {{ implode(' · ', array_filter([
                    $topic ? $module?->title : null,
                    ($session->manual ? 'Logged for ' : 'Started ').$started->format('D j M, H:i'),
                    $ended && ! $session->manual ? 'ended '.$ended->format('H:i') : null,
                ])) }}
            </p>
        </div>
        @include('livewire.workspaces.partials.row-menu', ['id' => 'session-'.$session->id, 'label' => 'this session', 'items' => array_values(array_filter([
            $open ? ['How the AI teaches', 'message-square-text', 'editTeaching', false] : null,
            $open ? [$session->usesPomodoro() ? 'Pomodoro settings' : 'Use the Pomodoro clock', 'timer', 'editPomodoro', false] : null,
            ['Delete session', 'trash-2', 'confirmDelete', false],
        ]))])
    </div>

    <livewire:workspaces.session-capture :workspace-id="$workspaceId" :session-id="$session->id" :key="'capture-'.$session->id" />
    <livewire:workspaces.flashcard-editor :workspace-id="$workspaceId" :key="'flashcard-editor-'.$session->id" />
    <x-toast :message="$notice" />

    @if ($error)
        <x-alert tone="danger">{{ $error }}</x-alert>
    @endif

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

    @if ($open && $session->usesPomodoro())
        @include('livewire.workspaces.partials.pomodoro-clock')
    @elseif ($open)
        <section aria-labelledby="clock-heading" class="clock-bar session-clock">
            <h2 id="clock-heading" class="sr-only">Clock</h2>
            <div class="clock-bar-main">
                <p @class(['status-chip', 'status-understood' => $session->state === 'running', 'status-covered' => $session->state === 'break'])>
                    <x-icon :name="$stateIcons[$session->state]" class="size-3.5" />{{ $session->stateWords() }}
                </p>
                <p class="session-time tabular-nums">
                    <span class="sr-only">Study time: {{ SessionDetails::duration($session->studySeconds) }}</span>
                    <span aria-hidden="true" data-clock data-base="{{ $session->studySeconds }}" data-running="{{ $session->state === 'running' ? '1' : '0' }}" data-drawn="{{ microtime(true) }}">{{ gmdate('G:i:s', $session->studySeconds) }}</span>
                </p>
                @if ($session->state === 'break')
                    <p class="text-sm text-fg-muted">break <span class="tabular-nums" data-clock data-base="{{ $session->breakSeconds }}" data-running="1" data-drawn="{{ microtime(true) }}">{{ gmdate('G:i:s', $session->breakSeconds) }}</span></p>
                @elseif ($session->breakSeconds > 0)
                    <p class="text-sm text-fg-muted">breaks {{ SessionDetails::duration($session->breakSeconds) }}</p>
                @endif
            </div>
            <div class="clock-bar-controls">
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
                <x-button icon="square" wire:click="confirmEnd">End session</x-button>
            </div>
        </section>
    @endif

    <section aria-labelledby="do-heading-{{ $uid }}">
        <h2 id="do-heading-{{ $uid }}" class="sr-only">What to do</h2>
        <ul class="action-grid" role="list">
            @foreach ($tiles as [$key, $name, $icon, $colour, $meta, $action])
                <li wire:key="tile-{{ $key }}">
                    <button type="button" class="action-tile" aria-describedby="tile-{{ $key }}-{{ $uid }}" {{ new \Illuminate\View\ComponentAttributeBag($action) }}>
                        <span class="item-icon ws-colour-{{ $colour }}" aria-hidden="true"><x-icon :name="$icon" class="size-5" /></span>
                        <span class="action-tile-name">{{ $name }}</span>
                        <span id="tile-{{ $key }}-{{ $uid }}" class="item-meta" aria-hidden="true">{{ $meta }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    </section>

    <livewire:workspaces.question-board :workspace-id="$workspaceId" :module-id="$module?->id" :session-id="$session->id" :quiet="true" :key="'questions-'.$session->id" />

    @if ($session->summary || $session->checkpoint)
        <section aria-labelledby="tutor-heading" class="overview-card space-y-3">
            <h2 id="tutor-heading" class="font-semibold">From the tutor</h2>
            @if ($session->summary)
                <div class="space-y-1">
                    <h3 class="text-sm font-semibold text-fg-muted">Summary</h3>
                    <p class="break-words">{{ $session->summary }}</p>
                </div>
            @endif
            @if ($session->checkpoint)
                <div class="space-y-1">
                    <h3 class="text-sm font-semibold text-fg-muted">Where it stands</h3>
                    <p class="break-words">{{ $session->checkpoint }}</p>
                </div>
            @endif
        </section>
    @endif

    @unless ($open)
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
            <p class="border-t border-divider pt-3 font-medium">Studied {{ $studied }}</p>
        </section>
    @endunless

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
                    <h2 id="session-dialog-title" class="min-w-0 flex-1 text-lg font-semibold" tabindex="-1" autofocus>{{ ['end' => 'End this session?', 'delete' => 'Delete this session?', 'pomodoro' => 'Session clock', 'teaching' => 'How the AI teaches', 'briefing' => 'Study with an AI', 'material' => 'Notes & files'][$mode] }}</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>
                <div class="space-y-4 px-5 pt-2">
                    @if ($mode === 'briefing')
                        <p class="text-sm text-fg-muted">Copy this into any AI, or download it and attach it. It tells the AI how to teach you and where you are.</p>
                        @if ($briefing->trimmed)
                            <x-alert tone="info" :live="false">Some things were left out to keep it short. The most relevant come first.</x-alert>
                        @endif
                        <pre class="briefing-text" tabindex="0" aria-label="Briefing text" x-ref="text">{{ $briefing->markdown }}</pre>
                        <p class="text-sm text-fg-muted">About {{ number_format($briefing->tokens()) }} tokens · <button type="button" class="text-link" wire:click="editTeaching">How the AI teaches</button></p>
                        <p class="sr-only" role="status" x-text="copied ? 'Copied to the clipboard.' : ''"></p>
                    @elseif ($mode === 'material')
                        @if ($open)
                            <p class="text-sm text-fg-muted">Press Use on what the AI should get: a note's text, or a file's name for you to share.</p>
                        @endif
                        @if ($material !== [])
                            <ul class="item-list" role="list" aria-label="{{ $module ? 'In '.$module->title : 'Used' }}">
                                @foreach ($material as $item)
                                    @include('livewire.workspaces.partials.material-row')
                                @endforeach
                            </ul>
                        @elseif ($module)
                            <p class="text-fg-muted">Nothing in {{ $module->title }} yet.</p>
                        @endif
                        @if ($open && $otherMaterial !== [])
                            <h3 class="section-title pt-2">{{ $module ? 'Elsewhere' : 'In this workspace' }}</h3>
                            <ul class="item-list" role="list" aria-label="{{ $module ? 'Elsewhere' : 'In this workspace' }}">
                                @foreach ($otherMaterial as $item)
                                    @include('livewire.workspaces.partials.material-row')
                                @endforeach
                            </ul>
                        @endif
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
                    @elseif ($mode === 'material')
                        <x-button variant="primary" x-on:click="$el.closest('dialog').close()">Done</x-button>
                    @else
                        <x-button x-on:click="$el.closest('dialog').close()">{{ $mode === 'end' ? 'Keep studying' : 'Cancel' }}</x-button>
                        <x-button type="submit" :variant="$mode === 'delete' ? 'danger' : 'primary'" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">{{ ['end' => 'End session', 'delete' => 'Delete', 'pomodoro' => 'Save', 'teaching' => 'Save'][$mode] }}</x-button>
                    @endif
                </div>
            </form>
        @endif
    </dialog>
</div>
