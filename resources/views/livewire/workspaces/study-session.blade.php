{{--
    A study session's page (App\Livewire\Workspaces\StudySession), chat first (docs/specs/vistud-2-blueprint.md §3.5.4):
    the page template's header (the module above the title, the clock and End in its action slot, the ⋯ menu), then the
    conversation filling the page with the rail beside it: the module's topics (tap to switch), its material, what this
    session saved. On a phone the rail is a sheet opened from the topic name. The questions board and the timeline are on
    the module's Questions and Sessions tabs. Ending the session is one screen (the `end` panel, then `done`).
--}}
@php
    use App\Study\SessionDetails;
    use App\Study\Topics;
    use Illuminate\Support\Str;

    $open = $session->isOpen();
    $statusIcons = ['not_started' => 'circle-dot', 'covered' => 'check', 'understood' => 'circle-check', 'confused' => 'circle-alert', 'mastered' => 'shield-check'];
    $statusWords = ['not_started' => 'Not started', 'covered' => 'Covered', 'understood' => 'Understood', 'confused' => 'Still confusing', 'mastered' => 'Mastered'];
    $stateIcons = ['running' => 'timer', 'paused' => 'pause', 'break' => 'coffee', 'ended' => 'square'];
    $studied = SessionDetails::duration($session->studySeconds).($session->breakSeconds > 0 ? ', with '.SessionDetails::duration($session->breakSeconds).' of breaks' : '');
    // The header's second word is the mode: the topic, the whole module (or the folder it is in), a quiz, a test, or free.
    $heading = match ($session->mode) {
        'module' => ($folder ? $folder->name : 'Whole module').($topic ? ' · '.$topic->name : ''),
        'quiz' => 'Quiz'.($topic ? ' · '.$topic->name : ($placeName ? ' · '.$placeName : '')),
        'test' => 'Test'.($placeName ? ' · '.$placeName : ''),
        'free' => 'Free study',
        default => $topic?->name ?? $placeName ?? 'Study session',
    };
    // Back goes to where the session is: its folder, its module, or the course.
    $backHref = match (true) {
        $folder !== null => route('workspaces.folders.show', [$workspaceId, $folder->id]),
        $module !== null => route('workspaces.modules.show', [$workspaceId, $module->id]),
        default => route('workspaces.show', $workspaceId),
    };
    $context = implode(' · ', array_filter([
        ($session->manual ? 'Logged for ' : 'Started ').$started->format('D j M, H:i'),
        $ended && ! $session->manual ? 'ended '.$ended->format('H:i') : null,
    ]));
@endphp
<div class="session-page">
    <x-page :title="$heading" :back-href="$backHref" :back-to="$placeName ?? 'Course'" :eyebrow="$folder ? $module->title.' › '.$folder->name : $module?->title" :context="$context">
        <x-slot:menu>
            @if ($open)
                <button type="button" class="menu-item" wire:click="editTeaching"><x-icon name="message-square-text" class="size-4" />How the AI teaches</button>
                <button type="button" class="menu-item" wire:click="editPomodoro"><x-icon name="timer" class="size-4" />{{ $session->usesPomodoro() ? 'Pomodoro settings' : 'Use the Pomodoro clock' }}</button>
                @if ($module)
                    <button type="button" class="menu-item" wire:click="tellTutor"><x-icon name="message-square-text" class="size-4" />Tell the tutor about this module</button>
                @endif
                @if (! $session->usesPomodoro() && $session->state !== 'break')
                    <button type="button" class="menu-item" wire:click="takeBreak"><x-icon name="coffee" class="size-4" />Take a break</button>
                @endif
                @if ($copyPaste)
                    <button type="button" class="menu-item" wire:click="showBriefing"><x-icon name="clipboard-copy" class="size-4" />Prompt for another AI</button>
                    <button type="button" class="menu-item" x-data x-on:click="Livewire.dispatch('capture-open')"><x-icon name="clipboard-paste" class="size-4" />Save from another AI</button>
                @endif
            @endif
            <button type="button" class="menu-item" wire:click="confirmDelete"><x-icon name="trash-2" class="size-4" />Delete session</button>
        </x-slot:menu>
        <x-slot:action>
            @if ($open && ! $session->usesPomodoro())
                <div class="session-clock-inline">
                    <p @class(['status-chip', 'status-understood' => $session->state === 'running', 'status-covered' => $session->state === 'break'])>
                        <x-icon :name="$stateIcons[$session->state]" class="size-3.5" />{{ $session->stateWords() }}
                    </p>
                    <p class="session-time-inline tabular-nums">
                        <span class="sr-only">Study time: {{ SessionDetails::duration($session->studySeconds) }}</span>
                        <span aria-hidden="true" data-clock data-base="{{ $session->studySeconds }}" data-running="{{ $session->state === 'running' ? '1' : '0' }}" data-drawn="{{ microtime(true) }}">{{ gmdate('G:i:s', $session->studySeconds) }}</span>
                    </p>
                    @if ($session->state === 'running')
                        <x-button icon="pause" wire:click="pause">Pause</x-button>
                    @elseif ($session->state === 'paused')
                        <x-button variant="primary" icon="play" wire:click="resume">Resume</x-button>
                    @else
                        <x-button variant="primary" icon="play" wire:click="resume">Back to studying</x-button>
                    @endif
                    <x-button icon="square" wire:click="confirmEnd">End</x-button>
                </div>
            @endif
        </x-slot:action>
    </x-page>

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
    @endif

    <div class="session-layout">
        <div class="session-main">
            @if ($open)
                {{-- On a phone: the rail is a sheet, opened from the topic. --}}
                <button type="button" class="topic-switch" x-data x-on:click="$dispatch('session-rail-open')">
                    <x-icon name="list-checks" class="size-4" /><span class="truncate">{{ $topic?->name ?? 'Choose a topic' }}</span><x-icon name="chevron-down" class="size-4" />
                </button>
            @endif
            <livewire:workspaces.tutor-chat :workspace-id="$workspaceId" :session-id="$session->id" :key="'chat-'.$session->id" />
            @if (! $open && ($session->summary || $session->checkpoint))
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
        </div>
        <aside class="session-rail" aria-label="Topics, material and what this session saved">
            @include('livewire.workspaces.partials.session-rail', ['suffix' => 'side'])
        </aside>
    </div>

    {{-- The rail as a sheet, for a phone. --}}
    <dialog id="session-rail-sheet" class="modal" aria-labelledby="session-rail-title"
        wire:ignore.self
        x-data
        x-on:session-rail-open.window="$el.open || $el.showModal()"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel">
            <div class="modal-head">
                <h2 id="session-rail-title" class="min-w-0 flex-1 text-lg font-semibold">{{ $placeName ?? 'This session' }}</h2>
                <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()"><x-icon name="x" /></button>
            </div>
            <div class="rail-body px-5 pb-5">
                @include('livewire.workspaces.partials.session-rail', ['suffix' => 'sheet'])
            </div>
        </div>
    </dialog>

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
                    <h2 id="session-dialog-title" class="min-w-0 flex-1 text-lg font-semibold" tabindex="-1" autofocus>{{ ['end' => 'End this session?', 'done' => 'Session ended', 'delete' => 'Delete this session?', 'pomodoro' => 'Session clock', 'teaching' => 'How the AI teaches', 'briefing' => 'Prompt for another AI', 'material' => 'Material for the tutor', 'topic' => 'What this session is about', 'question' => 'Ask a question', 'tell' => 'Tell the tutor about this module'][$mode] }}</h2>
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
                        <pre class="briefing-text" tabindex="0" aria-label="Prompt text" x-ref="text">{{ $briefing->markdown }}</pre>
                        <p class="text-sm text-fg-muted">About {{ number_format($briefing->tokens()) }} tokens · <button type="button" class="text-link" wire:click="editTeaching">How the AI teaches</button></p>
                        <p class="sr-only" role="status" x-text="copied ? 'Copied to the clipboard.' : ''"></p>
                    @elseif ($mode === 'material')
                        @php
                            $lower = fn ($item) => Str::lower($item['name']);
                            $every = array_map($lower, [...array_merge([], ...array_column($groups, 'items')), ...$alsoUsed]);
                        @endphp
                        <div class="space-y-4" x-data="{ q: '', has(name) { const q = this.q.trim().toLowerCase(); return q === '' || name.includes(q); } }">
                            @if ($every !== [])
                                <div class="search-field">
                                    <x-icon name="search" class="size-4" />
                                    <label for="material-search" class="sr-only">Search {{ $placeName ? 'the notes and files in '.$placeName : 'notes and files' }}</label>
                                    <input id="material-search" type="search" class="input" placeholder="Search {{ $placeName ?? 'notes and files' }}" x-model="q" autocomplete="off">
                                </div>
                                @if ($open)
                                    <p class="text-sm text-fg-muted">What you use, the tutor reads, and it goes in the prompt for another AI.</p>
                                @endif
                            @else
                                <div class="empty-place">
                                    <span class="item-icon" aria-hidden="true"><x-icon name="folder-open" class="size-5" /></span>
                                    <p class="font-medium">{{ $placeName ? 'Nothing in '.$placeName.' yet' : 'No notes or files yet' }}</p>
                                </div>
                            @endif
                            @if ($alsoUsed !== [])
                                <section class="space-y-2" x-show="@js(array_map($lower, $alsoUsed)).some((n) => has(n))">
                                    <h3 class="section-title">Also given to the AI</h3>
                                    <ul class="item-list" role="list" aria-label="Also given to the AI">
                                        @foreach ($alsoUsed as $item)
                                            @include('livewire.workspaces.partials.material-row')
                                        @endforeach
                                    </ul>
                                </section>
                            @endif
                            @foreach ($groups as $group)
                                <section class="material-group space-y-2" wire:key="group-{{ $group['key'] }}" style="--depth: {{ min($group['depth'], 4) }}" x-show="@js(array_map($lower, $group['items'])).some((n) => has(n))">
                                    @if ($group['title'] !== null)
                                        <h3 class="material-group-title"><x-icon name="folder" class="size-4" />{{ $group['title'] }}</h3>
                                    @endif
                                    <ul class="item-list" role="list" aria-label="{{ $group['title'] ?? 'In '.$placeName }}">
                                        @foreach ($group['items'] as $item)
                                            @include('livewire.workspaces.partials.material-row')
                                        @endforeach
                                    </ul>
                                </section>
                            @endforeach
                            @if ($every !== [])
                                <p class="text-fg-muted" x-show="! @js($every).some((n) => has(n))" x-cloak>Nothing matches.</p>
                            @endif
                        </div>
                    @elseif ($mode === 'topic')
                        @php
                            $isHere = fn ($t) => $inside !== null ? $t->in($inside) : ($module && $t->moduleId === $module->id);
                            $here = array_values(array_filter($courseTopics, $isHere));
                            $elsewhere = array_values(array_filter($courseTopics, fn ($t) => ! $isHere($t)));
                        @endphp
                        <p class="text-sm text-fg-muted">The tutor teaches from it, and the flashcards, questions and key points you save go to it. The tutor can set it too, when you agree.</p>
                        <div class="field">
                            <label for="session-topic" class="field-label">Topic</label>
                            <select id="session-topic" class="input" wire:model.live="topicChoice" @error('topicChoice') aria-invalid="true" @enderror>
                                <option value="">No topic</option>
                                @if ($here !== [])
                                    <optgroup label="{{ $placeName }}">
                                        @foreach ($here as $t)
                                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                @if ($elsewhere !== [])
                                    <optgroup label="{{ $here !== [] ? 'Other topics' : 'Topics' }}">
                                        @foreach ($elsewhere as $t)
                                            <option value="{{ $t->id }}">{{ $t->name }}</option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                <option value="new">A new topic…</option>
                            </select>
                            @error('topicChoice') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        @if ($topicChoice === 'new')
                            <div class="field">
                                <label for="session-new-topic" class="field-label">Name</label>
                                <input id="session-new-topic" type="text" class="input" wire:model="newTopic" maxlength="{{ Topics::MAX_NAME }}" autofocus aria-describedby="session-new-topic-hint" @error('newTopic') aria-invalid="true" @enderror>
                                <p id="session-new-topic-hint" class="field-hint">{{ $placeName ? 'Added to '.$placeName.'.' : 'Added to the course.' }} Short, like a chapter heading.</p>
                                @error('newTopic') <p class="field-error">{{ $message }}</p> @enderror
                            </div>
                        @endif
                    @elseif ($mode === 'teaching')
                        @include('livewire.workspaces.partials.teaching-fields')
                        <p class="text-sm text-fg-muted">The prompt asks for this from now on. An AI you already gave it to needs the new prompt, or to be told.</p>
                    @elseif ($mode === 'pomodoro')
                        @include('livewire.workspaces.partials.pomodoro-fields')
                        <p class="text-sm text-fg-muted">{{ $session->usesPomodoro() ? 'Time already studied stays, and the current phase keeps its progress with the new lengths.' : 'Time already studied stays. The first focus period starts counting now.' }}</p>
                    @elseif ($mode === 'end')
                        <p>You studied {{ $studied }}.</p>
                        @if ($touched !== [])
                            <fieldset class="space-y-3">
                                <legend class="field-label mb-1">Where you stand</legend>
                                @foreach ($touched as $t)
                                    @php $given = $activity['statuses'][$t->id] ?? null; @endphp
                                    <div class="end-topic" wire:key="end-topic-{{ $t->id }}">
                                        <p class="font-medium break-words">{{ $t->name }}</p>
                                        <p class="text-sm text-fg-muted">
                                            @if ($given)
                                                {{ $given['applied'] ? 'The tutor marked it' : 'The tutor suggests' }} {{ $statusWords[$given['to']] ?? $given['to'] }}{{ ($given['reason'] ?? null) ? ': '.$given['reason'] : '.' }}
                                            @else
                                                {{ $t->status !== null ? 'Now: '.($statusWords[$t->status] ?? $t->status).($t->byTutor() ? ' (marked by the tutor)' : '').'.' : 'No status yet.' }}
                                            @endif
                                        </p>
                                        <div class="segmented" role="radiogroup" aria-label="Where you stand on {{ $t->name }}">
                                            @foreach (['' => 'Leave it', 'covered' => 'Covered', 'understood' => 'Understood', 'confused' => 'Still confusing'] as $value => $word)
                                                <label>
                                                    <input type="radio" name="end-status-{{ $t->id }}" value="{{ $value }}" wire:model="statuses.{{ $t->id }}">
                                                    <span>{{ $word }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </fieldset>
                        @endif
                        @if ($activity['saved'] !== [] || $activity['quizzes'] > 0)
                            <p class="text-sm text-fg-muted">Saved in this session: {{ implode(', ', array_filter([\App\Livewire\Workspaces\TutorChat::savedWords($activity['saved']), $activity['quizzes'] > 0 ? Str::plural('quiz or test', $activity['quizzes'], prependCount: true) : null])) }}.</p>
                        @endif
                        @if ($chatted)
                            <p class="text-sm text-fg-muted">The chat's summary and where it stopped are saved with the session, for the next one to start from.</p>
                        @endif
                    @elseif ($mode === 'done')
                        <p>You studied {{ $studied }}.</p>
                        @if ($session->summary)
                            <div class="space-y-1">
                                <h3 class="text-sm font-semibold text-fg-muted">Summary</h3>
                                <p class="break-words">{{ $session->summary }}</p>
                            </div>
                        @elseif ($chatted)
                            <p class="text-sm text-fg-muted">The tutor's summary couldn't be written just now. It will be saved the next time you open this session.</p>
                        @endif
                    @elseif ($mode === 'question')
                        <div class="field">
                            <label for="session-question" class="field-label">Your question</label>
                            <textarea id="session-question" class="input" rows="3" wire:model="questionText" maxlength="{{ \App\Study\Questions::MAX_TEXT }}" autofocus @error('questionText') aria-invalid="true" @enderror></textarea>
                            <p class="field-hint">{{ $topic ? 'Kept with '.$topic->name.($module ? ' in '.$module->title : '').'.' : ($module ? 'Kept in '.$module->title.'.' : 'Kept in this course.') }}</p>
                            @error('questionText') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                    @elseif ($mode === 'tell')
                        <div class="field">
                            <label for="session-module-note" class="field-label">What should the tutor know about {{ $module?->title }}?</label>
                            <textarea id="session-module-note" class="input" rows="5" wire:model="moduleNote" maxlength="{{ \App\Study\Instructions::MAX_TEXT }}" autofocus @error('moduleNote') aria-invalid="true" @enderror></textarea>
                            <p class="field-hint">For example: the exam covers only the first half, or use the lecturer's slides.</p>
                            @error('moduleNote') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
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
                    @elseif ($mode === 'done')
                        <a href="{{ $backHref }}" class="btn btn-primary" wire:navigate>Done</a>
                    @else
                        <x-button x-on:click="$el.closest('dialog').close()">{{ $mode === 'end' ? 'Keep studying' : 'Cancel' }}</x-button>
                        <x-button type="submit" :variant="$mode === 'delete' ? 'danger' : 'primary'" wire:loading.attr="aria-busy" wire:target="save" busy-label="{{ $mode === 'end' ? 'Ending…' : 'Saving…' }}">{{ ['end' => 'End session', 'delete' => 'Delete', 'pomodoro' => 'Save', 'teaching' => 'Save', 'topic' => 'Save', 'question' => 'Keep question', 'tell' => 'Save'][$mode] }}</x-button>
                    @endif
                </div>
            </form>
        @endif
    </dialog>
</div>
