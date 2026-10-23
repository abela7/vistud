{{--
    The built-in chat on a study session's page (App\Livewire\Workspaces\TutorChat): the turns (the tutor's as Markdown,
    its marks as labelled quotes, what it saved and the statuses it set or proposed as chips with a tap to undo or accept),
    then the dock: quick asks as chips (Quiz me, Cards, Note this, Where are we), a line to write in with a + menu, and
    what the chat has cost. The student's words show at once; the tutor's answer streams
    in as it is written (wire:stream), with what it is looking up meanwhile. When the engine failed on the last
    message, "Try again" answers it without sending it twice. Notes and files from the module can be attached to
    a message; a file or picture can be uploaded, pasted or dropped into the box (resources/js/tutor-chat.js).
--}}
@php
    use App\Engine\Choices;
    use Illuminate\Support\Str;
@endphp
<section class="chat" aria-labelledby="chat-heading-{{ $this->getId() }}"
    x-data="tutorChat(@js(['upload' => $upload, 'account' => $account, 'mentionItems' => $attachable['items'] ?? [], 'mentions' => true, 'review' => ($activity ?? null) !== null]))"
    x-on:chat-done.window="done($event.detail.restore)"
    x-on:notes-changed.window="noteChanged($event.detail.notes)"
    x-on:session-toggle-tools.window="toggleTools()"
    x-on:session-layout-changed.window="if (menu) positionToolsPopover(); if (follow) $nextTick(() => stick(false))"
    x-on:resize.window="if (menu) positionToolsPopover()"
    x-on:keydown.window="dictationKey($event)"
    x-on:scroll.window="if (menu) positionToolsPopover()">
    <h2 id="chat-heading-{{ $this->getId() }}" class="sr-only">{{ $vistud ? 'ViStud AI' : 'Your tutor' }}</h2>

    {{-- Floating Quote / Reply pill on text selection in the chat --}}
    <div id="chat-selection-quote-pill-{{ $this->getId() }}" class="chat-selection-quote-pill" data-chat-quote-pill x-ref="quotePill" popover="manual" hidden>
        <button type="button" class="chat-quote-pill-btn" x-on:mousedown.prevent x-on:click="replyToSelection()" title="Quote and reply to this selection">
            <x-icon name="text-quote" class="size-3.5 text-accent" />
            <span>Reply</span>
        </button>
    </div>

    @if (! $ready && $sharedConsent)
        <div class="chat-empty">
            <p class="font-medium">Start the AI</p>
            <p class="text-sm text-fg-muted">It's ready for you, no key needed. Your messages and the study material you use are sent to OpenRouter, to the model chosen for you ({{ $choices->tutorModel }}).</p>
            <x-button variant="primary" icon="check" wire:click="agreeToAi" wire:loading.attr="aria-busy" wire:target="agreeToAi" busy-label="Starting…">Agree and start</x-button>
            <a href="{{ route('settings', ['part' => 'ai']) }}" class="text-sm text-link">Choose models in AI settings</a>
        </div>
    @elseif (! $ready)
        <div class="chat-empty">
            <p class="font-medium">Set up your AI first</p>
            <p class="text-sm text-fg-muted">Your key, the model that tutors you, and your consent: one page, a few minutes.</p>
            <a href="{{ route('settings', ['part' => 'ai']) }}" class="btn btn-primary"><x-icon name="brain" class="size-4" />AI settings</a>
        </div>
    @else
        @if (($pendingReview ?? []) !== [])
            <div wire:init="reviewFiles" class="sr-only" role="status">Reviewing what you uploaded.</div>
        @endif
        @if ($turns === [] && (($onTopic ?? null) || $vistud || ($activity ?? null)))
            {{-- A topic's empty chat, ViStud AI's, or an assignment companion's: what it is about, and ways in as cards. --}}
            <div class="chat-empty chat-empty-topic" x-show="! live">
                <span class="chat-empty-badge" aria-hidden="true"><x-icon name="sparkles" class="size-5" /></span>
                @if ($vistud)
                    <p class="chat-empty-title">Ask me anything about your study</p>
                    <p class="chat-empty-hint">I see all your courses, deadlines and sessions, and what your tutors remember about you.</p>
                @elseif ($activity ?? null)
                    <p class="chat-empty-title">Assignment Companion</p>
                    <p class="chat-empty-hint">Ask questions about your brief, plan your approach, or check your work against the criteria.</p>
                @else
                    <p class="chat-empty-title">Ask anything about {{ $onTopic }}</p>
                    <p class="chat-empty-hint">Taught from this topic's notes, files and key points, at your level.</p>
                @endif
                @if ($open && ! ($activity ?? null))
                    <ul class="chat-starters" role="list">
                        @foreach ($starters as $starter)
                            <li>
                                <button type="button" class="chat-starter" x-on:click="say(@js($starter['prompt']))" x-bind:disabled="live" title="{{ $starter['prompt'] }}">
                                    <span class="chat-starter-icon" aria-hidden="true"><x-icon :name="$starter['icon']" class="size-4" /></span>
                                    <span class="chat-starter-text">
                                        <span class="chat-starter-title">{{ $starter['title'] }}</span>
                                        <span class="chat-starter-hint">{{ $starter['hint'] }}</span>
                                    </span>
                                    <x-icon name="arrow-right" class="chat-starter-go size-4" />
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @elseif ($turns === [])
            <div class="chat-empty" x-show="! live">
                <p class="font-medium">Say hello, or ask anything about this course.</p>
                <p class="text-sm text-fg-muted max-sm:hidden">The tutor uses your notes, topics, and earlier sessions to help you study.</p>
                @if ($open)
                    <ul class="chat-chips" role="list">
                        @foreach ($suggestions as $suggestion)
                            <li><button type="button" class="chat-chip" x-on:click="say(@js($suggestion))" x-bind:disabled="live">{{ $suggestion }}</button></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
        @if (($onTopic ?? null) && $turns !== [])
            {{-- A topic's chat starts over from here (a session's, from the session's page). --}}
            <div class="chat-topic-bar">
                <button type="button" class="btn btn-ghost btn-sm" wire:click="startOver" wire:confirm="Start this chat over? The conversation is cleared." x-bind:disabled="live">
                    <x-icon name="rotate-ccw" class="size-4" />Start over
                </button>
            </div>
        @endif
        <ol class="chat-log" role="list" aria-label="The chat" tabindex="0" x-ref="log"
            x-on:scroll.passive="onScroll()"
            x-on:wheel.passive="onWheel($event)"
            x-show="live || $el.querySelector('[data-turn]') !== null" @if ($turns === []) x-cloak @endif>
            @foreach ($turns as $i => $turn)
                @if ($i > 0 && ! ($turn['folded'] ?? false) && ($turns[$i - 1]['folded'] ?? false))
                    {{-- Where the AI's memory of this chat starts: what is above was summarised for it, so a long chat keeps going. --}}
                    <li wire:key="folded-{{ $i }}" class="chat-topic-change chat-folded" x-show="!live || {{ $i }} < turnCount">
                        <span class="chat-topic-change-text"><x-icon name="layers" class="size-3.5" />Earlier messages are summarised for the AI, so the chat can keep going. You still see them all.</span>
                    </li>
                @endif
                @if ($turn['topicChangeWords'])
                    {{-- Where the student changed the session's topic on its page (the tutor's own changes show under its reply). --}}
                    <li wire:key="topic-change-{{ $i }}" class="chat-topic-change" x-show="!live || {{ $i }} < turnCount">
                        <span class="chat-topic-change-text"><x-icon name="tag" class="size-3.5" />{{ $turn['topicChangeWords'] }}</span>
                    </li>
                @endif
                <li wire:key="turn-{{ $i }}" data-turn @class(['chat-turn', 'is-me' => $turn['role'] === 'user', 'is-tutor' => $turn['role'] === 'assistant']) x-show="!live || {{ $i }} < turnCount">
                    @if ($turn['role'] === 'user')
                        @if ($turn['text'] !== '')
                            @php
                                $hasQuote = false;
                                $quotedText = '';
                                $messageText = $turn['text'];
                                if (str_starts_with($turn['text'], '>')) {
                                    $parts = explode("\n\n", $turn['text'], 2);
                                    $quoteLines = explode("\n", $parts[0]);
                                    $allLinesQuote = count(array_filter($quoteLines, fn($l) => !str_starts_with(trim($l), '>'))) === 0;
                                    if ($allLinesQuote) {
                                        $quotedText = trim(implode("\n", array_map(fn($l) => preg_replace('/^>\s?/', '', $l), $quoteLines)));
                                        $messageText = trim($parts[1] ?? '');
                                        $hasQuote = true;
                                    }
                                }
                            @endphp
                            @if ($hasQuote)
                                <div class="chat-bubble chat-bubble-with-quote">
                                    <div class="chat-bubble-quote" title="Quoted excerpt">
                                        <div class="chat-bubble-quote-bar">
                                            <x-icon name="text-quote" class="size-3 text-accent" />
                                            <span class="chat-bubble-quote-label">Quoting {{ $vistud ? 'ViStud AI' : 'tutor' }}</span>
                                        </div>
                                        <blockquote class="chat-bubble-quote-text">{{ $quotedText }}</blockquote>
                                    </div>
                                    @if ($messageText !== '')
                                        <p class="chat-bubble-text">{{ $messageText }}</p>
                                    @endif
                                </div>
                            @else
                                <p class="chat-bubble">{{ $turn['text'] }}</p>
                            @endif
                        @endif
                        @if ($turn['attachments'] !== [])
                            <ul class="chat-files" role="list" aria-label="Attached">
                                @foreach ($turn['attachments'] as $item)
                                    <li>
                                        @if ($item['url'])
                                            <a class="chat-file @if (($item['thumbUrl'] ?? null) || ($item['kind'] === 'picture' && str_starts_with($item['ref'], 'file:'))) has-thumb @endif" href="{{ $item['url'] }}" target="_blank" rel="noopener">
                                                @if (($item['thumbUrl'] ?? null) || ($item['kind'] === 'picture' && str_starts_with($item['ref'], 'file:')))
                                                    <span class="chat-file-thumb-wrap">
                                                        <img src="{{ $item['thumbUrl'] ?? route('files.content', str_replace('file:', '', $item['ref'])) }}" alt="{{ $item['name'] }}" class="chat-file-thumb" loading="lazy">
                                                    </span>
                                                @else
                                                    <x-icon :name="$item['icon']" class="size-4" />
                                                @endif
                                                <span class="truncate">{{ $item['name'] }}</span>
                                            </a>
                                        @else
                                            <span class="chat-file @if (($item['thumbUrl'] ?? null) || ($item['kind'] === 'picture' && str_starts_with($item['ref'], 'file:'))) has-thumb @endif">
                                                @if (($item['thumbUrl'] ?? null) || ($item['kind'] === 'picture' && str_starts_with($item['ref'], 'file:')))
                                                    <span class="chat-file-thumb-wrap">
                                                        <img src="{{ $item['thumbUrl'] ?? route('files.content', str_replace('file:', '', $item['ref'])) }}" alt="{{ $item['name'] }}" class="chat-file-thumb" loading="lazy">
                                                    </span>
                                                @else
                                                    <x-icon :name="$item['icon']" class="size-4" />
                                                @endif
                                                <span class="truncate">{{ $item['name'] }}</span>
                                            </span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @else
                        @if ($turn['text'] !== '')
                            {{-- A finished reply never changes; ignored by updates, so its drawn diagrams and formulas stay. --}}
                            <div class="chat-bubble chat-markdown" wire:ignore>{!! $turn['html'] !!}</div>
                        @endif
                        @if ($turn['savedWords'] || $turn['notes'] !== [] || ($turn['topic'] ?? null) !== null || $turn['remembered'] !== [])
                            {{-- What the tutor did in the course this turn: the session's topic, saved, and the notes it wrote in (open beside the chat). --}}
                            <ul class="chat-files chat-did" role="list" aria-label="Done in your course">
                                @if (($turn['topic'] ?? null) !== null)
                                    <li><span class="chat-file"><x-icon name="tag" class="size-4" /><span class="truncate">Topic: {{ $turn['topic'] }}</span></span></li>
                                @endif
                                @if ($turn['savedWords'])
                                    <li><span class="chat-file"><x-icon name="circle-check" class="size-4" /><span class="truncate">Saved {{ $turn['savedWords'] }}</span></span></li>
                                @endif
                                @foreach ($turn['notes'] as $note)
                                    <li><a class="chat-file" href="{{ $note['url'] }}" target="_blank" data-note-window x-on:click.prevent="$dispatch('session-open-note', { url: @js($note['url']), title: @js($note['title']) })"><x-icon name="notebook-pen" class="size-4" /><span class="truncate">Wrote in {{ $note['title'] }}</span></a></li>
                                @endforeach
                                {{-- What the tutor remembered about the student, with a tap to forget it (all of it is in Instructions for the AI). --}}
                                @foreach ($turn['remembered'] as $memory)
                                    <li class="chat-mark" wire:key="remembered-{{ $i }}-{{ $memory['id'] }}">
                                        @if ($memory['kept'])
                                            <button type="button" class="chat-file" title="See and change what your tutor remembers" x-on:click="Livewire.dispatch('memories-open', { id: @js($memory['id']) })"><x-icon name="brain" class="size-4" /><span class="truncate">Remembered: {{ $memory['text'] }}</span></button>
                                            <button type="button" class="chat-file-action" wire:click="forgetMemory('{{ $memory['id'] }}')">Forget<span class="sr-only"> that</span></button>
                                        @else
                                            <span class="chat-file" title="{{ $memory['text'] }}"><x-icon name="undo-2" class="size-4" /><span class="truncate">Forgotten: {{ $memory['text'] }}</span></span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($turn['marks'] !== [] || ($turn['quizzes'] ?? []) !== [])
                            {{-- Where the tutor says they stand: set (undo it), suggested (accept it), or decided; and a quiz or test kept. --}}
                            <ul class="chat-files chat-did" role="list" aria-label="Where you stand">
                                @foreach ($turn['quizzes'] as $quiz)
                                    <li><span class="chat-file"><x-icon name="list-checks" class="size-4" /><span class="truncate">{{ ucfirst($quiz['kind']) }} kept: {{ $quiz['score'] }} % over {{ Str::plural('question', $quiz['asked'], prependCount: true) }}</span></span></li>
                                @endforeach
                                @foreach ($turn['marks'] as $mark)
                                    <li class="chat-mark" wire:key="mark-{{ $i }}-{{ $mark['topic_id'] }}">
                                        @if ($mark['state'] === 'proposed')
                                            <span class="chat-file" @if ($mark['reason']) title="{{ $mark['reason'] }}" @endif><x-icon name="circle-help" class="size-4" /><span class="truncate">{{ $mark['topic'] }}: {{ $mark['words'] }}?</span></span>
                                            <button type="button" class="chat-file-action" wire:click="applyStatus('{{ $mark['topic_id'] }}', '{{ $mark['to'] }}')">Mark it<span class="sr-only"> {{ $mark['topic'] }} as {{ $mark['words'] }}</span></button>
                                        @elseif ($mark['state'] === 'marked')
                                            <span class="chat-file" @if ($mark['reason']) title="{{ $mark['reason'] }}" @endif><x-icon name="circle-check" class="size-4" /><span class="truncate">{{ $mark['topic'] }}: {{ $mark['words'] }}, marked by the tutor</span></span>
                                            <button type="button" class="chat-file-action" wire:click="undoStatus('{{ $mark['topic_id'] }}')">Undo<span class="sr-only"> marking {{ $mark['topic'] }}</span></button>
                                        @elseif ($mark['state'] === 'accepted')
                                            <span class="chat-file"><x-icon name="circle-check" class="size-4" /><span class="truncate">{{ $mark['topic'] }}: {{ $mark['words'] }}</span></span>
                                        @else
                                            <span class="chat-file"><x-icon name="undo-2" class="size-4" /><span class="truncate">{{ $mark['topic'] }}: {{ $mark['words'] }}, undone</span></span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ((($turn['sources_kept'] ?? []) !== []) || (($turn['stages_changed'] ?? []) !== []) || (($turn['log_entries'] ?? []) !== []) || (($turn['understandings_confirmed'] ?? []) !== []) || (($turn['plans_saved'] ?? []) !== []) || (($turn['plan_items_updated'] ?? []) !== []))
                            <ul class="chat-files chat-did" role="list" aria-label="Assignment progress">
                                @foreach ($turn['sources_kept'] ?? [] as $source)
                                    <li><span class="chat-file"><x-icon name="file-text" class="size-4" /><span class="truncate">Saved source: {{ $source['title'] }}</span></span></li>
                                @endforeach
                                @foreach ($turn['understandings_confirmed'] ?? [] as $confirmed)
                                    <li><span class="chat-file"><x-icon name="circle-check" class="size-4" /><span class="truncate">Confirmed understanding (version {{ $confirmed['revision'] }})</span></span></li>
                                @endforeach
                                @foreach ($turn['plans_saved'] ?? [] as $savedPlan)
                                    <li><span class="chat-file"><x-icon name="list-todo" class="size-4" /><span class="truncate">Saved assignment plan ({{ $savedPlan['added'] }} {{ $savedPlan['added'] === 1 ? 'item' : 'items' }})</span></span></li>
                                @endforeach
                                @foreach ($turn['plan_items_updated'] ?? [] as $updatedItem)
                                    <li><span class="chat-file"><x-icon name="circle-check" class="size-4" /><span class="truncate">Marked {{ $updatedItem['kind'] }} “{{ $updatedItem['title'] }}” as {{ $updatedItem['state'] }}</span></span></li>
                                @endforeach
                                @foreach ($turn['stages_changed'] ?? [] as $stage)
                                    <li><span class="chat-file"><x-icon name="arrow-right" class="size-4" /><span class="truncate">Moved to stage: {{ ucfirst((string) ($stage['to'] ?? $stage['stage'] ?? '')) }}</span></span></li>
                                @endforeach
                                @foreach ($turn['log_entries'] ?? [] as $log)
                                    <li class="chat-mark" wire:key="log-{{ $i }}-{{ $log['id'] }}">
                                        <span class="chat-file"><x-icon name="scroll-text" class="size-4" /><span class="truncate">Logged {{ $log['kind'] }}: {{ $log['text'] }}</span></span>
                                        <button type="button" class="chat-file-action" wire:click="undoLog('{{ $log['id'] }}')">Undo<span class="sr-only"> log entry</span></button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <div class="chat-turn-footer">
                            @if ($turn['looked'] !== [] || $turn['cost_micros'] > 0)
                                <p class="chat-meta">{{ implode(' · ', array_filter([
                                    $turn['looked'] !== [] ? 'Looked up: '.implode(', ', $turn['looked']) : null,
                                    $turn['cost_micros'] > 0 ? ($turn['cost_micros'] >= 5_000 ? Choices::dollars($turn['cost_micros']) : 'under 1¢') : null,
                                ])) }}</p>
                            @else
                                <span></span>
                            @endif
                            @if ($turn['text'] !== '')
                                <button type="button" class="chat-turn-reply-btn" x-on:click="replyToTurn(@js(Str::limit(strip_tags($turn['text']), 250)))" title="Reply to this response" aria-label="Reply to this response">
                                    <x-icon name="text-quote" class="size-3" />
                                    <span>Reply</span>
                                </button>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
            @if ($topicChangeAfter)
                <li wire:key="topic-change-after" class="chat-topic-change">
                    <span class="chat-topic-change-text"><x-icon name="tag" class="size-3.5" />{{ $topicChangeAfter }}</span>
                </li>
            @endif
            {{-- The turn under way: the student's words at once, then the answer as it streams in. --}}
            <li wire:key="live-me" class="chat-turn is-me" x-show="pending !== '' || pendingFiles.length > 0" x-cloak>
                <template x-if="pendingQuote !== ''">
                    <div class="chat-bubble chat-bubble-with-quote" x-show="pending !== ''">
                        <div class="chat-bubble-quote" title="Quoted excerpt">
                            <div class="chat-bubble-quote-bar">
                                <x-icon name="text-quote" class="size-3 text-accent" />
                                <span class="chat-bubble-quote-label">Quoting {{ $vistud ? 'ViStud AI' : 'tutor' }}</span>
                            </div>
                            <blockquote class="chat-bubble-quote-text" x-text="pendingQuote"></blockquote>
                        </div>
                        <p class="chat-bubble-text" x-show="pendingWords !== ''" x-text="pendingWords"></p>
                    </div>
                </template>
                <template x-if="pendingQuote === ''">
                    <p class="chat-bubble" x-show="pending !== ''" x-text="pending"></p>
                </template>
                <ul class="chat-files" role="list" x-show="pendingFiles.length > 0">
                    <template x-for="item in pendingFiles" :key="item.ref">
                        <li>
                            <span class="chat-file" :class="{ 'has-thumb': Boolean(item.thumbUrl || item.kind === 'picture') }">
                                <template x-if="item.thumbUrl || item.kind === 'picture'">
                                    <span class="chat-file-thumb-wrap">
                                        <img :src="item.thumbUrl || ('/files/' + item.ref.replace('file:', '') + '/content')" :alt="item.name" class="chat-file-thumb" loading="lazy">
                                    </span>
                                </template>
                                <template x-if="! (item.thumbUrl || item.kind === 'picture')">
                                    <svg class="size-4 shrink-0" fill="none" aria-hidden="true" focusable="false"><use :href="icon(item.kind)"></use></svg>
                                </template>
                                <span class="truncate" x-text="item.name"></span>
                            </span>
                        </li>
                    </template>
                </ul>
            </li>
            <li wire:key="live-tutor" class="chat-turn is-tutor" x-show="live" x-cloak>
                <p class="chat-meta chat-status" role="status" wire:stream.replace="status">Thinking…</p>
                <div class="sr-only" wire:stream.replace="answer" x-ref="streamBuffer" aria-hidden="true"></div>
                <div class="chat-bubble chat-markdown chat-live" x-ref="liveBubble" x-show="liveBubbleVisible"></div>
            </li>
        </ol>

        @if ($open)
          <div id="chat-menu-{{ $this->getId() }}" class="chat-picker chat-menu chat-tools-popover"
              x-ref="toolsPopover"
              x-show="menu"
              x-cloak
              x-bind:style="toolsStyle"
              x-on:click.outside="if (!$event.target.closest('[data-session-tools-btn]')) { menu = false; quizSub = false; }"
              x-on:keydown.escape.window="menu = false; quizSub = false"
              role="group" aria-label="Tools">
              <div class="chat-tools-section">
                  <p class="chat-tools-heading">Study with tutor</p>
                  <div class="chat-tools-grid">
                      @if ($quizzes !== [])
                          <button type="button" class="chat-tool-pill" :class="{ 'is-active': quizSub }" x-on:click="quizSub = ! quizSub" x-bind:aria-expanded="quizSub.toString()" aria-controls="chat-quiz-{{ $this->getId() }}" x-bind:disabled="busy">
                              <x-icon name="list-checks" class="size-3.5" /><span>Quiz me</span><x-icon name="chevron-down" class="size-3" />
                          </button>
                      @endif
                      @foreach ($actions as [$label, $icon, $ask])
                          <button type="button" class="chat-tool-pill" x-on:click="menu = false; say(@js($ask))" x-bind:disabled="busy">
                              <x-icon :name="$icon" class="size-3.5" /><span>{{ $label }}</span>
                          </button>
                      @endforeach
                  </div>
                  @if ($quizzes !== [])
                      <div id="chat-quiz-{{ $this->getId() }}" class="chat-quiz-inline" role="group" aria-label="Quiz me" x-show="quizSub" x-cloak>
                          @foreach ($quizzes as $quiz)
                              <button type="button" class="chat-pick" x-on:click="quizSub = false; menu = false; say(@js($quiz['text']))"><span class="truncate">{{ $quiz['label'] }}</span></button>
                          @endforeach
                          @if ($hasModule)
                              <button type="button" class="chat-pick" x-on:click="quizSub = false; menu = false; say(@js($testAsk))"><span class="truncate">Test me on the module</span></button>
                          @endif
                      </div>
                  @endif
              </div>

              <div class="chat-tools-section">
                  <div class="flex items-center justify-between px-1.5 py-0.5">
                      <p class="chat-tools-heading p-0">Study plugins</p>
                      <button type="button" class="text-xs text-accent hover:underline font-medium cursor-pointer" x-on:click="menu = false; Livewire.dispatch('session-open-plugins')">
                          Browse all ({{ count($tutoring['plugins'] ?? []) }}/{{ count(\App\Study\Plugins::all()) }})
                      </button>
                  </div>
                  <div class="chat-tools-grid">
                      @foreach (\App\Study\Plugins::all() as $p)
                          @php $isInstalled = in_array($p->id, $tutoring['plugins'] ?? [], true); @endphp
                          <button type="button"
                              class="chat-tool-pill {{ $isInstalled ? 'is-active' : '' }}"
                              wire:click="togglePlugin('{{ $p->id }}')"
                              title="{{ $p->name }}: {{ $p->description }} (Click to {{ $isInstalled ? 'uninstall' : 'install' }})">
                              <x-icon :name="$p->icon" class="size-3.5 {{ $isInstalled ? 'text-accent' : '' }}" />
                              <span>{{ $p->name }}</span>
                              @if ($isInstalled)
                                  <x-icon name="check" class="size-2.5 text-success" />
                              @endif
                          </button>
                      @endforeach
                  </div>
              </div>

              <div class="chat-tools-section">
                  <p class="chat-tools-heading">Course actions</p>
                  <button type="button" class="chat-pick" x-on:click="menu = false; Livewire.dispatch('session-open-plugins')"><x-icon name="puzzle" class="size-4 text-accent" /><span class="truncate flex-1 font-medium">Study plugins library</span><span class="text-xs px-1.5 py-0.5 rounded-full {{ count($tutoring['plugins'] ?? []) > 0 ? 'bg-accent-subtle text-accent font-semibold' : 'bg-surface text-fg-subtle border border-border' }}">{{ count($tutoring['plugins'] ?? []) > 0 ? count($tutoring['plugins'] ?? []).' installed' : 'Install' }}</span></button>
                  <button type="button" class="chat-pick" x-on:click="menu = false; $dispatch('session-toggle-note')"><x-icon name="notebook-pen" class="size-4" /><span class="truncate">Open side note</span></button>
                  <button type="button" class="chat-pick" x-on:click="menu = false; Livewire.dispatch('session-new-question')"><x-icon name="circle-help" class="size-4" /><span class="truncate">Ask a question</span></button>
                  <button type="button" class="chat-pick" x-on:click="menu = false; Livewire.dispatch('session-new-card')"><x-icon name="gallery-vertical-end" class="size-4" /><span class="truncate">New flashcard</span></button>
                  <button type="button" class="chat-pick" x-on:click="menu = false; Livewire.dispatch('session-new-note')"><x-icon name="file-plus" class="size-4" /><span class="truncate">Write a note</span></button>
                  <button type="button" class="chat-pick" x-on:click="menu = false; picking = true; $dispatch('session-ensure-chat')"><x-icon name="paperclip" class="size-4" /><span class="truncate">Attach a note, file or picture</span></button>
              </div>
          </div>

          <div class="chat-dock">
            <div class="chat-scroll-anchor" x-show="! follow && hasOverflow" x-cloak>
                <button type="button"
                    class="chat-scroll-latest"
                    x-on:click="scrollDown(true)"
                    aria-label="Scroll to latest messages"
                    title="Jump to latest">
                    <x-icon name="arrow-down" class="size-3.5" />
                    <span>Latest</span>
                </button>
            </div>
            @if ($waiting)
                <div class="chat-retry" x-show="! live">
                    @if ($vistud)
                        <p class="text-sm text-fg-muted">ViStud AI hasn't answered your last message.</p>
                    @else
                        <p class="text-sm text-fg-muted">The tutor hasn't answered your last message.</p>
                    @endif
                    @if ($trouble)
                        <p class="chat-retry-why">{{ $trouble['message'] }} <a href="{{ route('settings', ['part' => 'ai']) }}#try-models" wire:navigate>Try your models</a></p>
                    @endif
                    <x-button size="sm" icon="rotate-ccw" x-on:click="retry()">Try again</x-button>
                </div>
            @endif
            @if ($marked && ! $marksDismissed)
                <div class="chat-marked-bar" x-show="!marksDismissed && !live" x-cloak x-transition.opacity.duration.150ms>
                    <div class="chat-marked-pill" role="region" aria-label="Marked by tutor">
                        <button type="button" class="chat-marked-action" wire:click="keep">
                            <x-icon name="bookmark" class="size-3.5 text-accent" />
                            <span>Keep what the tutor marked</span>
                        </button>
                        <button type="button" class="chat-marked-dismiss" x-on:click="marksDismissed = true" wire:click="dismissMarks" title="Dismiss" aria-label="Dismiss without saving">
                            <x-icon name="x" class="size-3.5" />
                        </button>
                    </div>
                </div>
            @endif
            <form class="chat-compose" x-on:submit.prevent="submit()" x-on:dragover.prevent x-on:drop.prevent="drop($event)" novalidate>
                <div class="chat-input">
                    <div class="chat-reply-bar" x-show="quoteText !== ''" x-cloak>
                        <div class="chat-reply-bar-main">
                            <div class="chat-reply-bar-meta">
                                <x-icon name="text-quote" class="size-3 text-accent" />
                                <span class="chat-reply-bar-label">Replying to</span>
                            </div>
                            <p class="chat-reply-bar-text" x-text="quoteText"></p>
                        </div>
                        <button type="button" class="chat-reply-bar-cancel" x-on:click="clearQuote()" title="Cancel reply" aria-label="Cancel reply">
                            <x-icon name="x" class="size-3.5" />
                        </button>
                    </div>
                    <ul class="chat-files chat-attached" role="list" aria-label="Attached to your message" x-show="attached.length > 0 || uploading > 0" x-cloak>
                        <template x-for="item in attached" :key="item.ref">
                            <li class="chat-file" :class="{ 'has-thumb': Boolean(item.thumbUrl || item.kind === 'picture') }">
                                <template x-if="item.thumbUrl || item.kind === 'picture'">
                                    <span class="chat-file-thumb-wrap">
                                        <img :src="item.thumbUrl || ('/files/' + item.ref.replace('file:', '') + '/content')" :alt="item.name" class="chat-file-thumb" loading="lazy">
                                    </span>
                                </template>
                                <template x-if="! (item.thumbUrl || item.kind === 'picture')">
                                    <svg class="size-4 shrink-0" fill="none" aria-hidden="true" focusable="false"><use :href="icon(item.kind)"></use></svg>
                                </template>
                                <span class="truncate" x-text="item.name"></span>
                                <button type="button" class="chat-file-remove" x-on:click="remove(item.ref)" x-bind:aria-label="'Take off ' + item.name"><x-icon name="x" class="size-3.5" /></button>
                            </li>
                        </template>
                        <li class="chat-file" x-show="uploading > 0" role="status"><x-icon name="loader-circle" class="size-4 animate-spin" />Uploading…</li>
                    </ul>
                    {{-- Voice typing (resources/js/recorder.js): it records; Done writes the words into the box below. --}}
                    <div class="chat-dictation-pill" x-show="transcribing" x-cloak x-bind:class="{ 'is-quiet': ! hearing }">
                        <span class="chat-dictation-waves" aria-hidden="true">
                            <span class="chat-dictation-wave"></span>
                            <span class="chat-dictation-wave"></span>
                            <span class="chat-dictation-wave"></span>
                            <span class="chat-dictation-wave"></span>
                            <span class="chat-dictation-wave"></span>
                        </span>
                        <span class="chat-dictation-status" role="status" aria-live="polite" >Recording…</span>
                        <span class="chat-dictation-time" x-text="dictationTime" aria-hidden="true">0:00</span>
                        <span class="chat-dictation-hint"><kbd>Enter</kbd>, <kbd>Esc</kbd> or Done writes it down</span>
                        <div class="chat-dictation-actions">
                            <button type="button" class="chat-dictation-done-btn" x-on:click="stopDictation(true)" title="Stop and write down what you said (Esc)">
                                <x-icon name="check" class="size-3.5" />
                                <span>Done</span>
                            </button>
                            <button type="button" class="chat-dictation-cancel-btn" x-on:click="cancelDictation()" title="Stop and take out what you said" aria-label="Discard what you said">
                                <x-icon name="trash-2" class="size-4" />
                            </button>
                        </div>
                    </div>

                    <div class="chat-dictation-pill is-quiet" x-show="writingDown" x-cloak role="status">
                        <x-icon name="loader-circle" class="size-4 animate-spin" />
                        <span class="chat-dictation-status">Writing down what you said…</span>
                    </div>

                    {{-- File & Note @mention autocomplete popover --}}
                    <div class="chat-mention-popover"
                        x-show="mentionOpen"
                        x-cloak
                        x-ref="mentionMenu"
                        x-on:click.outside="mentionOpen = false"
                        x-transition:enter="transition ease-out duration-120"
                        x-transition:enter-start="opacity-0 translate-y-1 scale-98"
                        x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                        x-transition:leave-end="opacity-0 translate-y-1 scale-98"
                        role="dialog"
                        aria-label="Mention a note or file">
                        
                        <div class="chat-mention-head">
                            <div class="flex items-center gap-1.5 min-w-0">
                                <x-icon name="at-sign" class="size-3.5 text-accent shrink-0" />
                                <span class="text-xs font-semibold text-fg">Mention file or note</span>
                                <span class="chat-mention-count" x-text="filteredMentions.length + (filteredMentions.length === 1 ? ' match' : ' matches')"></span>
                            </div>
                            
                            <div class="chat-mention-filters" role="tablist">
                                <button type="button"
                                    class="chat-mention-filter-btn"
                                    :class="{ 'is-active': mentionCategory === 'all' }"
                                    x-on:click="mentionCategory = 'all'"
                                    role="tab"
                                    :aria-selected="mentionCategory === 'all'">
                                    All
                                </button>
                                <button type="button"
                                    class="chat-mention-filter-btn"
                                    :class="{ 'is-active': mentionCategory === 'notes' }"
                                    x-on:click="mentionCategory = 'notes'"
                                    role="tab"
                                    :aria-selected="mentionCategory === 'notes'">
                                    Notes
                                </button>
                                <button type="button"
                                    class="chat-mention-filter-btn"
                                    :class="{ 'is-active': mentionCategory === 'files' }"
                                    x-on:click="mentionCategory = 'files'"
                                    role="tab"
                                    :aria-selected="mentionCategory === 'files'">
                                    Files
                                </button>
                            </div>
                        </div>

                        <div class="chat-mention-scroll" role="listbox">
                            <template x-for="(item, idx) in filteredMentions" :key="item.ref">
                                <div class="chat-mention-item"
                                    :class="{ 'is-active': idx === mentionActiveIndex }"
                                    role="option"
                                    :aria-selected="idx === mentionActiveIndex"
                                    x-on:mouseenter="mentionActiveIndex = idx"
                                    x-on:mousedown.prevent="selectMention(item)">
                                    
                                    <div class="chat-mention-icon-wrap" :class="'is-' + item.kind">
                                        <template x-if="item.thumbUrl || item.kind === 'picture'">
                                            <img :src="item.thumbUrl" :alt="item.name" class="chat-mention-thumb" loading="lazy">
                                        </template>
                                        <template x-if="! (item.thumbUrl || item.kind === 'picture')">
                                            <span class="chat-mention-icon">
                                                <template x-if="item.kind === 'note'">
                                                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M13.4 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-7.4"/><path d="M2 6h4"/><path d="M2 10h4"/><path d="M2 14h4"/><path d="M2 18h4"/><path d="M21.378 5.626a1 1 0 1 0-3.004-3.004l-5.01 5.012a2 2 0 0 0-.506.854l-.837 2.87a.5.5 0 0 0 .62.62l2.87-.837a2 2 0 0 0 .854-.506z"/></svg>
                                                </template>
                                                <template x-if="item.kind !== 'note'">
                                                    <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                                                </template>
                                            </span>
                                        </template>
                                    </div>

                                    <div class="chat-mention-content">
                                        <div class="chat-mention-title">
                                            <span class="font-semibold text-fg truncate" x-text="item.name"></span>
                                            <template x-if="has(item.ref)">
                                                <span class="chat-mention-badge is-attached">Attached</span>
                                            </template>
                                            <template x-if="item.chosen && !has(item.ref)">
                                                <span class="chat-mention-badge is-session">In session</span>
                                            </template>
                                        </div>
                                        <div class="chat-mention-meta">
                                            <span x-text="item.kind === 'note' ? 'Study Note' : (item.ext ? item.ext.toUpperCase() + ' File' : 'Course File')"></span>
                                            <template x-if="item.inScope">
                                                <span>· In this module</span>
                                            </template>
                                        </div>
                                    </div>

                                    <div class="chat-mention-action" aria-hidden="true">
                                        <span class="chat-mention-enter">↵</span>
                                    </div>
                                </div>
                            </template>

                            <div class="chat-mention-empty" x-show="filteredMentions.length === 0">
                                <x-icon name="circle-help" class="size-5 text-fg-subtle mb-1" />
                                <p class="text-xs font-semibold text-fg">No matches found</p>
                                <p class="text-xs text-fg-muted" x-text="'No notes or files match @' + mentionQuery"></p>
                            </div>
                        </div>

                        <div class="chat-mention-foot">
                            <span class="chat-mention-tip"><kbd>↑</kbd><kbd>↓</kbd> navigate</span>
                            <span class="chat-mention-tip"><kbd>↵</kbd> or <kbd>Tab</kbd> mention &amp; attach</span>
                            <span class="chat-mention-tip"><kbd>esc</kbd> close</span>
                        </div>
                    </div>

                    @php $boxWords = $vistud ? 'Ask ViStud AI… (Enter sends, Shift+Enter for a new line, @ to mention files & notes)' : 'Write to your tutor… (Enter sends, Shift+Enter for a new line, @ to mention files & notes)'; @endphp
                    <label for="chat-box-{{ $this->getId() }}" class="sr-only">{{ $vistud ? 'Ask ViStud AI' : 'Write to your tutor' }}</label>
                    <span x-ref="suggested" hidden>{{ $suggestedReply ?? '' }}</span>
                    <textarea id="chat-box-{{ $this->getId() }}" class="chat-box" rows="1" x-model="draft" x-ref="box" maxlength="8000"
                        placeholder="{{ $boxWords }}" data-idle-placeholder="{{ $boxWords }}" x-bind:disabled="live"
                        x-bind:class="{ 'is-dictating': transcribing }"
                        x-bind:placeholder="boxPlaceholder"
                        x-bind:title="suggested !== '' && draft.trim() === '' ? 'Press Tab to use this' : null"
                        x-on:input="handleInput($event)"
                        x-on:click="checkMentionTrigger()"
                        x-on:keyup="checkMentionTrigger()"
                        x-on:paste="paste($event); $nextTick(() => { resize(); checkMentionTrigger(); })"
                        x-on:keydown="handleKeydown($event)"></textarea>
                    <p class="chat-suggest-hint" x-show="suggested !== '' && draft.trim() === '' && !live && !transcribing" x-cloak>Tab uses this</p>
                </div>

                <div class="chat-compose-bar">
                    <div class="chat-compose-tools" role="toolbar" aria-label="Attachments and models">
                        {{-- ViStud AI attaches from any course, and its uploads go with the message only (into no course). --}}
                        <div class="chat-attach" x-on:keydown.escape.stop="picking = false" x-on:click.outside="picking = false">
                            <button type="button" class="chat-tool-btn chat-add-btn" :class="{ 'is-active': picking }" x-on:click="picking = ! picking; menu = false" x-bind:aria-expanded="picking.toString()"
                                aria-controls="chat-picker-{{ $this->getId() }}" aria-label="Add file, photo or note" title="Add file, photo or note" x-bind:disabled="busy">
                                <x-icon name="plus" class="size-4" />
                            </button>

                            <div id="chat-picker-{{ $this->getId() }}" class="chat-picker" x-show="picking" x-cloak role="group" aria-label="Attach a note, a file or a picture">
                                <label for="chat-find-{{ $this->getId() }}" class="sr-only">Find a note or a file</label>
                                <input id="chat-find-{{ $this->getId() }}" type="search" class="input chat-find" x-model="search" placeholder="Find a note or a file…" autocomplete="off">
                                <ul class="chat-picker-list" role="list">
                                    @forelse ($attachable['items'] as $item)
                                        <li x-show="matches(@js($item['name']))">
                                            <button type="button" class="chat-pick" x-on:click="toggle(@js(['ref' => $item['ref'], 'name' => $item['name'], 'kind' => $item['kind'], 'thumbUrl' => $item['thumbUrl'] ?? null]))" x-bind:aria-pressed="has(@js($item['ref'])).toString()">
                                                @if (($item['thumbUrl'] ?? null) || ($item['kind'] === 'picture' && str_starts_with($item['ref'], 'file:')))
                                                    <span class="chat-picker-thumb-wrap">
                                                        <img src="{{ $item['thumbUrl'] ?? route('files.content', str_replace('file:', '', $item['ref'])) }}" alt="{{ $item['name'] }}" class="chat-picker-thumb" loading="lazy">
                                                    </span>
                                                @else
                                                    <x-icon :name="['note' => 'notebook-pen', 'picture' => 'file-image'][$item['kind']] ?? 'file-text'" class="size-4" />
                                                @endif
                                                <span class="truncate">{{ $item['name'] }}</span>
                                                @if ($item['chosen'])
                                                    <span class="chat-pick-tag">This session</span>
                                                @endif
                                                <x-icon name="check" class="size-4 chat-pick-check" />
                                            </button>
                                        </li>
                                    @empty
                                        <li class="chat-picker-empty">No notes or files here yet. Upload one below.</li>
                                    @endforelse
                                </ul>
                                <div class="chat-picker-foot">
                                    <label class="btn btn-secondary chat-upload">
                                        <x-icon name="upload" class="size-4" />Upload a file or picture
                                        <input type="file" class="sr-only" multiple accept="{{ $upload['accept'] }}" x-on:change="chosen($event); picking = false">
                                    </label>
                                    <p class="text-xs text-fg-muted">{{ $sees ? 'You can also paste a screenshot into the box, or drop files on it.' : 'Your tutor model can\'t see pictures: choose one that can in your AI settings.' }}</p>
                                </div>
                            </div>
                        </div>

                        <button type="button" class="chat-tool-btn" :class="{ 'is-active': formatOpen }" x-on:click="formatOpen = ! formatOpen" x-bind:aria-expanded="formatOpen.toString()" title="Text formatting (Aa)" aria-label="Text formatting">
                            <span class="text-xs font-semibold leading-none">Aa</span>
                        </button>

                        <button type="button" class="chat-tool-btn" :class="{ 'is-active': mentionOpen }" x-on:click="openMentionPicker()" title="Mention a file or note (@)" aria-label="Mention a file or note">
                            <x-icon name="at-sign" class="size-3.5" />
                        </button>

                        <div class="chat-format-group" x-show="formatOpen" x-cloak x-transition>
                            <span class="chat-tool-divider" aria-hidden="true"></span>
                            <button type="button" class="chat-tool-btn" x-on:click="wrap('**', '**', 'bold text')" title="Bold" aria-label="Bold">
                                <x-icon name="bold" class="size-3.5" />
                            </button>
                            <button type="button" class="chat-tool-btn" x-on:click="wrap('*', '*', 'italic text')" title="Italic" aria-label="Italic">
                                <x-icon name="italic" class="size-3.5" />
                            </button>
                            <button type="button" class="chat-tool-btn" x-on:click="wrap('`', '`', 'code')" title="Code in a line" aria-label="Code in a line">
                                <x-icon name="code" class="size-3.5" />
                            </button>
                            <button type="button" class="chat-tool-btn" x-on:click="wrap('```\n', '\n```', 'code block')" title="Code block" aria-label="Code block">
                                <x-icon name="square-code" class="size-3.5" />
                            </button>
                            <button type="button" class="chat-tool-btn" x-on:click="toggleList()" title="Bulleted list" aria-label="Bulleted list">
                                <x-icon name="list" class="size-3.5" />
                            </button>
                            <button type="button" class="chat-tool-btn" x-on:click="wrap('$', '$', 'formula')" title="Formula" aria-label="Formula">
                                <x-icon name="sigma" class="size-3.5" />
                            </button>
                            <button type="button" class="chat-tool-btn" x-on:click="formatOpen = false" title="Close formatting" aria-label="Close formatting">
                                <x-icon name="x" class="size-3.5" />
                            </button>
                        </div>
                    </div>

                    <div class="chat-compose-actions">
                        <div class="chat-model-picker" x-data="{ modelOpen: false }" x-on:keydown.escape.stop="modelOpen = false" x-on:click.outside="modelOpen = false">
                            <button type="button" class="chat-model-btn" x-on:click="modelOpen = ! modelOpen" x-bind:aria-expanded="modelOpen.toString()" aria-label="Switch AI model" title="Current model: {{ $activeModelName }} (click to change)" x-bind:disabled="busy">
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

                        <span class="sr-only">{{ $activeModel }} · {{ $spentWords }}</span>
                        <button type="button" class="chat-mic-btn"
                            :class="{ 'is-active': transcribing }"
                            x-show="speechSupported"
                            x-cloak
                            x-on:click="toggleDictation()"
                            :title="transcribing ? 'Stop listening and keep the words (Esc or Alt+S)' : 'Speak instead of typing (Alt+S)'"
                            :aria-label="transcribing ? 'Stop listening' : 'Speak instead of typing'"
                            aria-keyshortcuts="Alt+S"
                            :aria-pressed="transcribing.toString()"
                            x-bind:disabled="busy">
                            <span x-show="! transcribing"><x-icon name="mic" class="size-4" /></span>
                            <span x-show="transcribing" x-cloak><x-icon name="mic-off" class="size-4" /></span>
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" x-show="review && attached.length > 0 && ! live" x-cloak x-on:click="reviewAttached()" x-bind:disabled="busy">Review</button>
                        <button type="submit" class="btn btn-primary chat-send" x-bind:disabled="busy" aria-label="Send">
                            <span x-show="! live"><x-icon name="arrow-up" class="size-4" /></span>
                            <span x-show="live" x-cloak><x-icon name="loader-circle" class="size-4 animate-spin" /></span>
                        </button>
                    </div>
                </div>
            </form>
            <p class="text-xs text-fg-muted" x-show="note !== ''" x-text="note" role="status" x-cloak></p>
            @if ($error)
                <p class="field-error" role="alert">{{ $error }}</p>
            @endif
          </div>
        @else
            <p class="text-sm text-fg-muted">This session has ended. The chat stays here to read; start a new session to go on.</p>
        @endif
    @endif
</section>
