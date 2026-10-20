{{--
    The course guide (App\Livewire\Workspaces\GuideChat), in the tutor chat's format (resources/js/guide-chat.js): the guide's
    replies on the left as Markdown, streaming in as they are written, the student's on the right. Setting a course up, it
    asks a question at a time: the kind of course, its level and where the student starts from with buttons (written at
    once, no AI asked), the rest in words; each with Skip, never asked again once skipped. Beside the talk, what the AI knows
    about the course, a line a question (press one to be asked again). What it proposes sits under the talk with a tick on
    every part, and only what is ticked is added. From Modules, the same talk adds modules.
--}}
@php
    use App\Engine\CourseGuide;
    use App\Livewire\Workspaces\GuideChat;
    use App\Study\CourseTypes;
    use App\Study\MarkdownPreview;
    use App\Study\ModuleParts;

    $proposed = $proposal;
    $count = 0;
    if ($proposed !== null) {
        $count = (int) ($ticks['course'] ?? false) + count(array_filter($ticks['facts'] ?? [])) + (int) ($ticks['about'] ?? false) + (int) ($ticks['outcomes'] ?? false) + (int) ($ticks['textbook'] ?? false)
            + count(array_filter($ticks['assessment'] ?? [])) + count(array_filter($ticks['modules'] ?? []));
    }
    $when = fn (?string $day) => $day === null ? null : \Illuminate\Support\Carbon::parse($day)->format('j M Y');
    $short = ['type' => 'Kind', 'level' => 'Level', 'background' => 'Your start', 'about' => 'About', 'assessment' => 'Assessment', 'textbook' => 'Textbook', 'tools' => 'Tools'];
    $known = count(array_filter($questions, fn ($state) => $state === 'done'));
    $factLabels = ['type' => 'Kind of course', 'level' => 'Level', 'language' => 'Taught in', 'tools' => 'Tools and notation', 'exam_format' => 'Exams'];
    $factValue = fn (string $key, mixed $value) => match ($key) {
        'type' => CourseTypes::label($value),
        'level' => CourseTypes::LEVELS[$value][0] ?? $value,
        'tools' => implode(', ', (array) $value),
        default => (string) $value,
    };
    $fresh = collect($talk)->where('from', 'you')->isEmpty();
@endphp
<div @class(['guide', 'has-steps' => $questions !== []]) x-data="guideChat()" data-fresh="{{ $fresh ? '1' : '0' }}" x-bind:class="{ 'is-fullscreen': fullscreen }" x-on:keydown.window="onKey($event)">
    <div role="status" aria-live="polite" class="empty:hidden"><x-toast :message="$notice" :tone="$noticeTone" /></div>

    @if ($questions !== [])
        {{-- What the AI knows about the course: a row of pills on a phone, a list beside the talk from 1024 px. --}}
        <nav class="guide-steps" aria-label="What the AI knows about this course">
            <p class="guide-steps-head"><span class="font-semibold">What the AI knows</span> <span class="text-fg-muted">{{ $known }} of {{ count($questions) }}</span></p>
            <ol class="guide-steps-list" role="list">
                @foreach ($questions as $question => $state)
                    <li>
                        <button type="button" @class(['guide-step', 'is-done' => $state === 'done', 'is-skipped' => $state === 'skipped', 'is-now' => $asking === $question])
                            x-on:click="pick(() => $wire.askAgain('{{ $question }}'))" x-bind:disabled="live"
                            aria-label="{{ $short[$question] }}, {{ ['done' => 'known', 'skipped' => 'skipped', 'open' => 'not asked yet'][$state] }}"
                            title="{{ $state === 'done' ? 'Known. Press to change it.' : ($state === 'skipped' ? 'Skipped. Press to answer it.' : 'Not asked yet. Press to answer it now.') }}">
                            <x-icon :name="match ($state) { 'done' => 'circle-check', 'skipped' => 'circle-minus', default => 'circle-dashed' }" class="size-4 shrink-0" />
                            <span class="truncate">{{ $short[$question] }}</span>
                        </button>
                    </li>
                @endforeach
            </ol>
            @if ($plugins !== [])
                <p class="guide-steps-note max-sm:hidden"><x-icon name="puzzle" class="size-4 shrink-0" /><span>New sessions start with {{ implode(' and ', array_filter($plugins)) }}.</span></p>
            @endif
        </nav>
    @endif

    <section class="chat guide-chat" aria-labelledby="guide-talk-title">
        <div class="guide-bar">
            <h2 id="guide-talk-title" class="guide-bar-title">{{ $for === 'modules' ? 'Add modules' : 'Set up the course' }}</h2>
            <button type="button" class="session-icon-btn" x-bind:class="{ 'is-active': fullscreen }" x-on:click="fullscreen = ! fullscreen" x-bind:aria-pressed="fullscreen.toString()" x-bind:title="fullscreen ? 'Exit full screen (Esc)' : 'Full screen'" aria-label="Toggle full screen">
                <x-icon name="maximize-2" class="size-3.5" x-show="! fullscreen" />
                <x-icon name="minimize-2" class="size-3.5" x-show="fullscreen" x-cloak />
            </button>
        </div>

        @if ($fresh)
            <div class="guide-fullscreen-offer max-sm:hidden" x-show="offerFullscreen && ! fullscreen" x-cloak role="status">
                <x-icon name="maximize-2" class="size-4 shrink-0 text-fg-muted" />
                <p>Full screen gives this talk more room.</p>
                <button type="button" class="btn btn-primary btn-sm" x-on:click="useFullscreen()">Use full screen</button>
                <button type="button" class="btn btn-ghost btn-sm" x-on:click="dismissFullscreen()">Not now</button>
            </div>
        @endif

        <div class="guide-log-wrap" role="log" aria-label="Talk with the guide" aria-live="polite">
        <ol class="chat-log guide-log" role="list" aria-label="The talk" tabindex="0" x-ref="log" x-on:scroll.passive="onScroll()">
            @foreach ($talk as $i => $line)
                @php $isLast = $loop->last; @endphp
                @if ($line['from'] === 'you')
                    <li class="chat-turn is-me" data-turn wire:key="guide-{{ $i }}">
                        <span class="sr-only">You: </span>
                        @if ($line['text'] !== '')<p class="chat-bubble">{{ $line['text'] }}</p>@endif
                        @if (($line['pictures'] ?? 0) > 0)
                            <p class="chat-meta"><x-icon name="image" class="inline size-3.5" /> {{ $line['pictures'] === 1 ? 'A picture' : $line['pictures'].' pictures' }} went with this.</p>
                        @endif
                        @if (($line['files'] ?? 0) > 0)
                            <p class="chat-meta"><x-icon name="file-text" class="inline size-3.5" /> {{ $line['files'] === 1 ? 'A file' : $line['files'].' files' }} went with this.</p>
                        @endif
                    </li>
                @elseif ($line['from'] === 'done')
                    <li class="chat-turn is-tutor" data-turn wire:key="guide-{{ $i }}">
                        <ul class="chat-files chat-did" role="list"><li><span class="chat-file"><x-icon name="circle-check" class="size-4" /><span>{{ $line['text'] }}</span></span></li></ul>
                    </li>
                @else
                    <li class="chat-turn is-tutor" data-turn wire:key="guide-{{ $i }}">
                        <span class="sr-only">Guide: </span>
                        {{-- A reply never changes once said; left alone by updates, so its formulas and diagrams stay drawn. --}}
                        <div class="chat-bubble chat-markdown" wire:ignore>{!! MarkdownPreview::html($line['text']) !!}</div>
                        @if ($isLast && isset($line['ask']) && $proposed === null)
                            @php $choices = CourseGuide::choices($line['ask']); @endphp
                            <ul @class(['guide-choices', 'is-grid' => $line['ask'] === 'type']) role="list" aria-label="Answers">
                                @foreach ($choices as $choice)
                                    <li>
                                        <button type="button" class="chat-chip guide-choice" x-on:click="pick(() => $wire.choose('{{ $line['ask'] }}', '{{ $choice['value'] }}'))" x-bind:disabled="live">
                                            @if ($choice['icon'])<x-icon :name="$choice['icon']" class="size-4 shrink-0" />@endif
                                            <span>{{ $choice['label'] }}</span>
                                        </button>
                                    </li>
                                @endforeach
                                <li>
                                    <button type="button" class="chat-chip guide-choice is-quiet" x-on:click="pick(() => $wire.skip('{{ $line['ask'] }}'))" x-bind:disabled="live">
                                        <x-icon name="skip-forward" class="size-4 shrink-0" /><span>{{ $choices === [] ? 'Skip this question' : "I don't know, skip" }}</span>
                                    </button>
                                </li>
                            </ul>
                        @endif
                    </li>
                @endif
            @endforeach

            {{-- The turn under way: the student's words at once, then the guide's answer as it streams in. --}}
            <li class="chat-turn is-me" x-show="live && (pending !== '' || pendingPictures > 0 || pendingFiles > 0)" x-cloak>
                <p class="chat-bubble" x-show="pending !== ''" x-text="pending"></p>
                <p class="chat-meta" x-show="pendingPictures > 0"><x-icon name="image" class="inline size-3.5" /> <span x-text="pendingPictures === 1 ? 'A picture' : pendingPictures + ' pictures'"></span> went with this.</p>
                <p class="chat-meta" x-show="pendingFiles > 0"><x-icon name="file-text" class="inline size-3.5" /> <span x-text="pendingFiles === 1 ? 'A file' : pendingFiles + ' files'"></span> went with this.</p>
            </li>
            <li class="chat-turn is-tutor" x-show="live" x-cloak wire:key="guide-live">
                <div class="chat-bubble chat-markdown chat-live" wire:stream.replace="guide-answer"><span class="ai-dots" aria-hidden="true"><span></span><span></span><span></span></span><span class="sr-only">Thinking…</span></div>
            </li>
            {{-- What the guide would add sits in the talk, so it scrolls with it and the box below stays in reach. --}}
            @if ($proposed !== null)
            <li class="chat-turn guide-proposal-turn" data-turn>
                <section class="guide-proposal" aria-labelledby="guide-proposal-title">
                    <div class="panel-head">
                        <span class="item-icon" aria-hidden="true"><x-icon name="sparkles" class="size-5" /></span>
                        <div class="panel-head-text">
                            <h3 id="guide-proposal-title" class="panel-title">I would add</h3>
                            <p class="panel-hint">Untick what you don't want.</p>
                        </div>
                    </div>

                    @if (($proposed['facts'] ?? []) !== [])
                        <fieldset class="guide-part">
                            <legend class="field-label">About the course</legend>
                            <ul class="guide-list is-ticks" role="list">
                                @foreach ($proposed['facts'] as $key => $value)
                                    <li wire:key="guide-fact-{{ $key }}">
                                        <x-checkbox :name="'guide-fact-'.$key" :id="'guide-fact-'.$key" wire:model.live="ticks.facts.{{ $key }}" :label="$factLabels[$key]" :hint="$factValue($key, $value).($key === 'type' ? ' (suggested)' : '')" />
                                    </li>
                                @endforeach
                            </ul>
                        </fieldset>
                    @endif

                    @if (($proposed['course'] ?? []) !== [])
                        <div class="guide-part">
                            <x-checkbox name="guide-course" id="guide-course" wire:model.live="ticks.course" label="The course details"
                                :hint="collect([$proposed['course']['code'] ?? null, $proposed['course']['term'] ?? null, isset($proposed['course']['starts_on']) ? 'starts '.$when($proposed['course']['starts_on']) : null, isset($proposed['course']['ends_on']) ? 'ends '.$when($proposed['course']['ends_on']) : null])->filter()->implode(' · ')" />
                        </div>
                    @endif

                    @if ($proposed['about'] !== '')
                        <div class="guide-part">
                            <x-checkbox name="guide-about" id="guide-about" wire:model.live="ticks.about" label="What the course is about" />
                            <p class="guide-text">{{ $proposed['about'] }}</p>
                        </div>
                    @endif

                    @if ($proposed['outcomes'] !== [])
                        <div class="guide-part">
                            <x-checkbox name="guide-outcomes" id="guide-outcomes" wire:model.live="ticks.outcomes" label="What it should teach" />
                            <ul class="guide-list" role="list">
                                @foreach ($proposed['outcomes'] as $outcome)<li>{{ $outcome }}</li>@endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($proposed['assessment'] !== [])
                        <fieldset class="guide-part">
                            <legend class="field-label">How it is assessed</legend>
                            <ul class="guide-list is-ticks" role="list">
                                @foreach ($proposed['assessment'] as $i => $item)
                                    <li wire:key="guide-assessment-{{ $i }}">
                                        <x-checkbox :name="'guide-assessment-'.$i" :id="'guide-assessment-'.$i" wire:model.live="ticks.assessment.{{ $i }}" :label="$item['name']"
                                            :hint="collect([\App\Study\Activities::KINDS[$item['kind']] ?? null, $item['weight'] !== null ? $item['weight'].'%' : null, $item['due_on'] !== null ? 'due '.$when($item['due_on']) : null])->filter()->implode(' · ')" />
                                        @if ($item['due_on'] !== null)
                                            <div class="guide-sub">
                                                <x-checkbox :name="'guide-assignment-'.$i" :id="'guide-assignment-'.$i" wire:model.live="ticks.assignments.{{ $i }}" label="Also add it as an assignment" />
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </fieldset>
                    @endif

                    @if ($proposed['textbook'] !== '')
                        <div class="guide-part">
                            <x-checkbox name="guide-textbook" id="guide-textbook" wire:model.live="ticks.textbook" label="The textbook" :hint="$proposed['textbook']" />
                        </div>
                    @endif

                    @if ($proposed['modules'] !== [])
                        <fieldset class="guide-part">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <legend class="field-label">Modules ({{ count($proposed['modules']) }})</legend>
                                <span class="flex gap-3 text-sm">
                                    <button type="button" class="quiet-link" wire:click="tickModules(true)">All</button>
                                    <button type="button" class="quiet-link" wire:click="tickModules(false)">None</button>
                                </span>
                            </div>
                            <p class="guide-module-note">Tap a topic or part to leave it out.</p>
                            <ul class="guide-modules" role="list">
                                @foreach ($proposed['modules'] as $i => $module)
                                    @php $on = (bool) ($ticks['modules'][$i] ?? false); @endphp
                                    <li @class(['guide-module', 'is-off' => ! $on]) wire:key="guide-module-{{ $i }}">
                                        <x-checkbox :name="'guide-module-'.$i" :id="'guide-module-'.$i" wire:model.live="ticks.modules.{{ $i }}" :label="$module['title']"
                                            :hint="collect([$when($module['starts_on']), $when($module['ends_on'])])->filter()->implode(' – ')" />
                                        @if (($module['about'] ?? '') !== '')
                                            <p class="guide-module-about">{{ $module['about'] }}</p>
                                        @endif
                                        @foreach (['topics' => 'Topics', 'parts' => 'Parts'] as $kind => $heading)
                                            @if (($module[$kind] ?? []) !== [])
                                                <div class="guide-module-row" role="group" aria-label="{{ $heading }} in {{ $module['title'] }}">
                                                    <span class="guide-module-label">{{ $heading }}</span>
                                                    <ul class="guide-picks" role="list">
                                                        @foreach ($module[$kind] as $j => $item)
                                                            @php
                                                                $name = $kind === 'topics' ? $item : $item['title'];
                                                                $icon = $kind === 'topics' ? 'tag' : ModuleParts::icon($item['kind'] ?? null);
                                                                $kindLabel = $kind === 'parts' ? ModuleParts::label($item['kind'] ?? null) : null;
                                                                $beside = $kind === 'parts' ? ModuleParts::beside($item['kind'] ?? null, $name) : null;
                                                            @endphp
                                                            <li wire:key="guide-{{ $kind }}-{{ $i }}-{{ $j }}">
                                                                <label class="guide-pick" title="{{ $kindLabel ?? $name }}">
                                                                    <input type="checkbox" class="sr-only" wire:model.live="ticks.{{ $kind }}.{{ $i }}.{{ $j }}" @disabled(! $on)>
                                                                    <x-icon :name="$icon" class="size-3.5 shrink-0" />
                                                                    <span>{{ $name }}</span>
                                                                    @if ($beside !== null)<span class="guide-pick-kind">· {{ $beside }}</span>@endif
                                                                </label>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endif
                                        @endforeach
                                    </li>
                                @endforeach
                            </ul>
                        </fieldset>
                    @endif

                    <div class="guide-actions">
                        <x-button type="button" variant="primary" icon="plus" wire:click="add" wire:loading.attr="aria-busy" wire:target="add" busy-label="Adding…" :disabled="$count === 0">Add what is ticked{{ $count > 0 ? " ({$count})" : '' }}</x-button>
                        <x-button type="button" wire:click="drop">Not now</x-button>
                    </div>
                </section>
            </li>
            @endif
        </ol>
        </div>

        <div class="chat-scroll-anchor" x-show="! follow" x-cloak>
            <button type="button" class="chat-scroll-latest" x-on:click="latest()"><x-icon name="arrow-down" class="size-4" />Latest</button>
        </div>

        <form class="guide-form" novalidate x-on:submit.prevent="submit()">
            @if ($problem !== null && $errors->isEmpty())
                <x-alert tone="warning">{{ $problem }} <a href="{{ route('settings', ['part' => 'ai']) }}" class="inline-link">AI settings</a></x-alert>
            @endif

            <div class="chat-compose">
                <div class="chat-dictation-pill" x-show="transcribing" x-cloak x-bind:class="{ 'is-quiet': ! hearing }" role="status">
                    <span class="chat-dictation-waves" aria-hidden="true">
                        <span class="chat-dictation-wave"></span><span class="chat-dictation-wave"></span><span class="chat-dictation-wave"></span><span class="chat-dictation-wave"></span><span class="chat-dictation-wave"></span>
                    </span>
                    <span class="chat-dictation-status" aria-live="polite" x-text="hearing ? 'Hearing you…' : 'Listening…'">Listening…</span>
                    <span class="chat-dictation-time" x-text="dictationTime" aria-hidden="true">0:00</span>
                    <span class="chat-dictation-hint"><kbd>Enter</kbd> sends · <kbd>Esc</kbd> or <kbd>Alt+S</kbd> stops</span>
                    <div class="chat-dictation-actions">
                        <button type="button" class="chat-dictation-done-btn" x-on:click="stopDictation(true)" title="Stop listening and keep the words (Esc)"><x-icon name="check" class="size-3" /><span>Done</span></button>
                        <button type="button" class="chat-dictation-cancel-btn" x-on:click="stopDictation(false)" title="Stop and take out what you said" aria-label="Discard what you said"><x-icon name="x" class="size-3.5" /></button>
                    </div>
                </div>

                {{-- A picture or a document of the course page or the timetable: pasted, dropped or chosen, it goes with the next message. --}}
                @if ($pictures !== [])
                    <ul class="chat-files guide-pictures" role="list" aria-label="Files to send" x-show="! live">
                        @foreach ($pictures as $i => $picture)
                            @php $isPicture = GuideChat::isPicture($picture); @endphp
                            <li class="chat-file" data-kind="{{ $isPicture ? 'picture' : 'file' }}" wire:key="guide-picture-{{ $i }}">
                                @if ($isPicture && method_exists($picture, 'isPreviewable') && $picture->isPreviewable())
                                    <img src="{{ $picture->temporaryUrl() }}" alt="" class="guide-thumb">
                                @else
                                    <x-icon :name="$isPicture ? 'image' : 'file-text'" class="size-4 shrink-0" />
                                @endif
                                <span class="truncate">{{ $picture->getClientOriginalName() }}</span>
                                <button type="button" class="chat-file-remove" wire:click="removePicture({{ $i }})" aria-label="Take {{ $picture->getClientOriginalName() }} off"><x-icon name="x" class="size-3.5" /></button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @php $boxWords = $for === 'modules' ? 'Paste the timetable or syllabus, tell me the modules, or ask me to suggest some…' : 'Answer, or paste the course page…'; @endphp
                <label for="guide-text" class="sr-only">Your message</label>
                <textarea id="guide-text" class="chat-box" rows="2" wire:model="text" maxlength="{{ CourseGuide::MAX_MESSAGE }}" x-ref="box"
                    placeholder="{{ $boxWords }}"
                    enterkeyhint="send" autocapitalize="sentences" x-bind:disabled="live"
                    x-bind:class="{ 'is-dictating': transcribing }"
                    x-bind:placeholder="transcribing ? 'Speak now. Your words appear here as you say them.' : @js($boxWords)"
                    x-on:paste="take($event); $nextTick(() => resize())" x-on:drop="take($event)" x-on:dragover.prevent
                    x-on:input="onInput($event)" x-on:keydown="keydown($event)"></textarea>

                <div class="chat-compose-bar">
                    <div class="chat-compose-tools" role="toolbar" aria-label="Files and formatting">
                        <label class="chat-tool-btn" title="Attach a picture or a file">
                            <x-icon name="paperclip" class="size-4" /><span class="sr-only">Attach a picture or a file</span>
                            <input type="file" class="sr-only" multiple accept="image/png,image/jpeg,image/webp,image/gif,.pdf,.docx,.pptx,.xlsx,.txt,.md,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" wire:model="pictures">
                        </label>
                        <button type="button" class="chat-tool-btn" :class="{ 'is-active': formatOpen }" x-on:click="formatOpen = !formatOpen" title="Formatting" aria-label="Formatting" :aria-expanded="formatOpen.toString()">
                            <span class="text-xs leading-none font-semibold">Aa</span>
                        </button>
                        <div class="chat-format-group" x-show="formatOpen" x-cloak x-transition>
                            <span class="chat-tool-divider" aria-hidden="true"></span>
                            <button type="button" class="chat-tool-btn" x-on:click="wrap('**', '**', 'bold text')" title="Bold" aria-label="Bold"><x-icon name="bold" class="size-4" /></button>
                            <button type="button" class="chat-tool-btn" x-on:click="wrap('*', '*', 'italic text')" title="Italic" aria-label="Italic"><x-icon name="italic" class="size-4" /></button>
                            <button type="button" class="chat-tool-btn" x-on:click="wrap('`', '`', 'code')" title="Code in a line" aria-label="Code in a line"><x-icon name="code" class="size-4" /></button>
                            <button type="button" class="chat-tool-btn" x-on:click="toggleList()" title="List" aria-label="List"><x-icon name="list" class="size-4" /></button>
                            <button type="button" class="chat-tool-btn" x-on:click="wrap('$', '$', 'formula')" title="Formula" aria-label="Formula"><x-icon name="sigma" class="size-4" /></button>
                        </div>
                    </div>

                    <div class="chat-compose-actions">
                        <div class="chat-model-picker" x-data="{ modelOpen: false }" x-on:keydown.escape.stop="modelOpen = false" x-on:click.outside="modelOpen = false">
                            <button type="button" class="chat-model-btn" x-on:click="modelOpen = ! modelOpen" x-bind:aria-expanded="modelOpen.toString()" aria-label="Switch AI model" title="Current model: {{ $activeModelName }} (click to change)" x-bind:disabled="live">
                                <span class="chat-model-btn-name">{{ $activeModelName }}</span>
                                <x-icon name="chevron-down" class="size-3 text-fg-muted transition-transform" ::class="{ 'rotate-180': modelOpen }" />
                            </button>
                            <div class="chat-model-popover" x-show="modelOpen" x-cloak x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" role="dialog" aria-label="Choose AI model">
                                <div class="chat-model-popover-head">
                                    <span class="text-xs font-semibold text-fg">AI model</span>
                                    <a href="{{ route('settings', ['part' => 'ai']) }}" class="text-xs text-link">Settings</a>
                                </div>
                                <div class="chat-model-popover-list" role="menu">
                                    @foreach ($chatModels as $m)
                                        <button type="button" role="menuitem" class="chat-model-option {{ $activeModel === $m['id'] ? 'is-active' : '' }}" wire:click="switchModel('{{ $m['id'] }}')" x-on:click="modelOpen = false">
                                            <div class="chat-model-option-text">
                                                <span class="chat-model-option-name">{{ $m['name'] }}</span>
                                                @if ($m['priceWords'])
                                                    <span class="chat-model-option-price">{{ $m['priceWords'] }}</span>
                                                @endif
                                            </div>
                                            @if ($activeModel === $m['id'])
                                                <x-icon name="check" class="size-3.5 text-accent flex-none" />
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                                <div class="chat-model-popover-foot" role="status">
                                    <span class="chat-model-usage">{{ $activeModel }} · {{ $spentWords }}</span>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="chat-mic-btn" :class="{ 'is-active': transcribing }" x-show="speechSupported" x-cloak x-on:click="toggleDictation()" :title="transcribing ? 'Stop listening and keep the words (Esc or Alt+S)' : 'Speak instead of typing (Alt+S)'" :aria-label="transcribing ? 'Stop listening' : 'Speak instead of typing'" aria-keyshortcuts="Alt+S" :aria-pressed="transcribing.toString()" x-bind:disabled="live">
                            <span x-show="! transcribing"><x-icon name="mic" class="size-4" /></span>
                            <span x-show="transcribing" x-cloak><x-icon name="mic-off" class="size-4" /></span>
                        </button>
                        <button type="submit" class="btn btn-primary chat-send" x-bind:aria-busy="live.toString()" x-bind:disabled="live" title="Send" aria-label="Send">
                            <span x-show="!live"><x-icon name="arrow-up" class="size-4" /></span>
                            <span x-show="live" x-cloak><x-icon name="loader-circle" class="size-4 animate-spin" /></span>
                        </button>
                    </div>
                </div>
            </div>

            @error('text')
                <p class="field-error">{{ $message }}@if ($problem !== null) <a href="{{ route('settings', ['part' => 'ai']) }}" class="inline-link">AI settings</a>@endif</p>
            @enderror
            <p class="chat-meta" wire:loading wire:target="pictures" role="status"><x-icon name="loader-circle" class="inline size-4 animate-spin" /> Getting the file…</p>
            @error('pictures')<p class="field-error">{{ $message }}</p>@enderror
            @error('pictures.*')<p class="field-error">{{ $message }}</p>@enderror
            <div class="guide-foot">
                <p class="chat-meta guide-hint">You can paste or drop a screenshot, or attach a Word, PDF or text file{{ $for === 'modules' ? ' of the timetable or syllabus' : ' of the course page' }}. Nothing is added until you tick it.</p>
                <div class="guide-actions">
                    @if ($toModules)
                        <a href="{{ route('workspaces.show', [$workspace->id, 'modules']) }}" class="btn btn-secondary btn-sm"><x-icon name="layers" class="size-4" />Go to Modules</a>
                    @endif
                    <a href="{{ route('workspaces.show', $workspace->id) }}" class="btn btn-secondary btn-sm">I'm done for now</a>
                </div>
            </div>
        </form>
    </section>


</div>
