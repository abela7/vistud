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
    x-data="tutorChat(@js(['upload' => $upload, 'account' => $account]))"
    x-on:chat-done.window="done($event.detail.restore)"
    x-on:notes-changed.window="noteChanged($event.detail.notes)">
    <h2 id="chat-heading-{{ $this->getId() }}" class="sr-only">Your tutor</h2>

    @if (! $ready)
        <div class="chat-empty">
            <p class="font-medium">Set up your AI first</p>
            <p class="text-sm text-fg-muted">Your key, the model that tutors you, and your consent: one page, a few minutes.</p>
            <a href="{{ route('engine.settings') }}" class="btn btn-primary"><x-icon name="brain" class="size-4" />AI settings</a>
        </div>
    @else
        @if ($turns === [])
            <div class="chat-empty" x-show="! live">
                <p class="font-medium">Say hello, or ask about anything in this course.</p>
                <p class="text-sm text-fg-muted">The tutor already knows where you stand: your topics, questions, notes and earlier sessions. It looks things up rather than guessing.</p>
                @if ($open)
                    <ul class="chat-chips" role="list">
                        @foreach ($suggestions as $suggestion)
                            <li><button type="button" class="chat-chip" x-on:click="say(@js($suggestion))" x-bind:disabled="live">{{ $suggestion }}</button></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
        <ol class="chat-log" role="list" aria-label="The chat" tabindex="0" x-ref="log"
            x-on:scroll="follow = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 80"
            x-show="live || $el.querySelector('[data-turn]') !== null" @if ($turns === []) x-cloak @endif>
            @foreach ($turns as $i => $turn)
                <li wire:key="turn-{{ $i }}" data-turn @class(['chat-turn', 'is-me' => $turn['role'] === 'user', 'is-tutor' => $turn['role'] === 'assistant'])>
                    @if ($turn['role'] === 'user')
                        @if ($turn['text'] !== '')
                            <p class="chat-bubble">{{ $turn['text'] }}</p>
                        @endif
                        @if ($turn['attachments'] !== [])
                            <ul class="chat-files" role="list" aria-label="Attached">
                                @foreach ($turn['attachments'] as $item)
                                    <li>
                                        @if ($item['url'])
                                            <a class="chat-file" href="{{ $item['url'] }}" target="_blank" rel="noopener"><x-icon :name="$item['icon']" class="size-4" /><span class="truncate">{{ $item['name'] }}</span></a>
                                        @else
                                            <span class="chat-file"><x-icon :name="$item['icon']" class="size-4" /><span class="truncate">{{ $item['name'] }}</span></span>
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
                        @if ($turn['savedWords'] || $turn['notes'] !== [] || ($turn['topic'] ?? null) !== null)
                            {{-- What the tutor did in the course this turn: the session's topic, saved, and the notes it wrote in (open beside the chat). --}}
                            <ul class="chat-files chat-did" role="list" aria-label="Done in your course">
                                @if (($turn['topic'] ?? null) !== null)
                                    <li><span class="chat-file"><x-icon name="tag" class="size-4" /><span class="truncate">Topic: {{ $turn['topic'] }}</span></span></li>
                                @endif
                                @if ($turn['savedWords'])
                                    <li><span class="chat-file"><x-icon name="circle-check" class="size-4" /><span class="truncate">Saved {{ $turn['savedWords'] }}</span></span></li>
                                @endif
                                @foreach ($turn['notes'] as $note)
                                    <li><a class="chat-file" href="{{ $note['url'] }}" target="_blank" data-note-window><x-icon name="notebook-pen" class="size-4" /><span class="truncate">Wrote in {{ $note['title'] }}</span></a></li>
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
                        @if ($turn['looked'] !== [] || $turn['cost_micros'] > 0)
                            <p class="chat-meta">{{ implode(' · ', array_filter([
                                $turn['looked'] !== [] ? 'Looked up: '.implode(', ', $turn['looked']) : null,
                                $turn['cost_micros'] > 0 ? ($turn['cost_micros'] >= 5_000 ? Choices::dollars($turn['cost_micros']) : 'under 1¢') : null,
                            ])) }}</p>
                        @endif
                    @endif
                </li>
            @endforeach
            {{-- The turn under way: the student's words at once, then the answer as it streams in. --}}
            <li wire:key="live-me" class="chat-turn is-me" x-show="pending !== '' || pendingFiles.length > 0" x-cloak>
                <p class="chat-bubble" x-show="pending !== ''" x-text="pending"></p>
                <ul class="chat-files" role="list" x-show="pendingFiles.length > 0">
                    <template x-for="item in pendingFiles" :key="item.ref">
                        <li><span class="chat-file"><svg class="size-4 shrink-0" fill="none" aria-hidden="true" focusable="false"><use :href="icon(item.kind)"></use></svg><span class="truncate" x-text="item.name"></span></span></li>
                    </template>
                </ul>
            </li>
            <li wire:key="live-tutor" class="chat-turn is-tutor" x-show="live" x-cloak>
                <p class="chat-meta chat-status" role="status" wire:stream.replace="status">Thinking…</p>
                <div class="chat-bubble chat-markdown chat-live" wire:stream.replace="answer"></div>
            </li>
        </ol>

        @if ($open)
          <div class="chat-dock">
            @if ($waiting)
                <div class="chat-retry" x-show="! live">
                    <p class="text-sm text-fg-muted">The tutor hasn't answered your last message.</p>
                    <x-button size="sm" icon="rotate-ccw" x-on:click="retry()">Try again</x-button>
                </div>
            @endif
            <ul class="chat-actions" role="list" aria-label="Quick asks">
                @if ($quizzes !== [])
                    <li class="chat-quiz" x-data="{ open: false }" x-on:keydown.escape.stop="open = false" x-on:click.outside="open = false">
                        <button type="button" class="chat-chip" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-controls="chat-quiz-{{ $this->getId() }}" x-bind:disabled="busy"><x-icon name="list-checks" class="size-4" />Quiz me<x-icon name="chevron-down" class="size-4" /></button>
                        <div id="chat-quiz-{{ $this->getId() }}" class="chat-quiz-menu" role="group" aria-label="Quiz me" x-show="open" x-cloak>
                            @foreach ($quizzes as $quiz)
                                <button type="button" class="chat-pick" x-on:click="open = false; say(@js($quiz['text']))"><span class="truncate">{{ $quiz['label'] }}</span></button>
                            @endforeach
                            @if ($hasModule)
                                <button type="button" class="chat-pick" x-on:click="open = false; say(@js($testAsk))"><span class="truncate">Test me on the module</span></button>
                            @endif
                        </div>
                    </li>
                @endif
                @foreach ($actions as [$label, $icon, $ask])
                    <li><button type="button" class="chat-chip" x-on:click="say(@js($ask))" x-bind:disabled="busy"><x-icon :name="$icon" class="size-4" />{{ $label }}</button></li>
                @endforeach
                @if ($marked)
                    <li><button type="button" class="chat-chip" wire:click="keep"><x-icon name="bookmark" class="size-4" />Keep what the tutor marked</button></li>
                @endif
            </ul>
            <form class="chat-compose" x-on:submit.prevent="submit()" x-on:dragover.prevent x-on:drop.prevent="drop($event)" novalidate>
                <div class="chat-attach" x-data="{ menu: false }" x-on:keydown.escape.stop="menu = false; picking = false" x-on:click.outside="menu = false; picking = false">
                    <button type="button" class="btn btn-ghost chat-attach-button" x-on:click="menu = ! menu; picking = false" x-bind:aria-expanded="menu.toString()"
                        aria-controls="chat-menu-{{ $this->getId() }}" aria-label="More: attach, ask a question, new card or note" x-bind:disabled="busy">
                        <x-icon name="plus" class="size-5" />
                    </button>
                    <div id="chat-menu-{{ $this->getId() }}" class="chat-picker chat-menu" x-show="menu" x-cloak role="group" aria-label="More">
                        <button type="button" class="chat-pick" x-on:click="menu = false; picking = true"><x-icon name="paperclip" class="size-4" /><span class="truncate">Attach a note, file or picture</span></button>
                        <button type="button" class="chat-pick" x-on:click="menu = false; Livewire.dispatch('session-new-question')"><x-icon name="circle-help" class="size-4" /><span class="truncate">Ask a question</span></button>
                        <button type="button" class="chat-pick" x-on:click="menu = false; Livewire.dispatch('session-new-card')"><x-icon name="gallery-vertical-end" class="size-4" /><span class="truncate">New flashcard</span></button>
                        <button type="button" class="chat-pick" x-on:click="menu = false; Livewire.dispatch('session-new-note')"><x-icon name="file-plus" class="size-4" /><span class="truncate">Write a note</span></button>
                    </div>
                    <div id="chat-picker-{{ $this->getId() }}" class="chat-picker" x-show="picking" x-cloak role="group" aria-label="Attach a note, a file or a picture">
                        <label for="chat-find-{{ $this->getId() }}" class="sr-only">Find a note or a file</label>
                        <input id="chat-find-{{ $this->getId() }}" type="search" class="input chat-find" x-model="search" placeholder="Find a note or a file…" autocomplete="off">
                        <ul class="chat-picker-list" role="list">
                            @forelse ($attachable['items'] as $item)
                                <li x-show="matches(@js($item['name']))">
                                    <button type="button" class="chat-pick" x-on:click="toggle(@js(['ref' => $item['ref'], 'name' => $item['name'], 'kind' => $item['kind']]))" x-bind:aria-pressed="has(@js($item['ref'])).toString()">
                                        <x-icon :name="['note' => 'notebook-pen', 'picture' => 'file-image'][$item['kind']] ?? 'file-text'" class="size-4" />
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
                                <input type="file" class="sr-only" multiple accept="{{ $upload['accept'] }}" x-on:change="chosen($event)">
                            </label>
                            <p class="text-xs text-fg-muted">{{ $sees ? 'You can also paste a screenshot into the box, or drop files on it.' : 'Your tutor model can\'t see pictures: choose one that can in your AI settings.' }}</p>
                        </div>
                    </div>
                </div>
                <div class="chat-input">
                    <ul class="chat-files chat-attached" role="list" aria-label="Attached to your message" x-show="attached.length > 0 || uploading > 0" x-cloak>
                        <template x-for="item in attached" :key="item.ref">
                            <li class="chat-file">
                                <svg class="size-4 shrink-0" fill="none" aria-hidden="true" focusable="false"><use :href="icon(item.kind)"></use></svg>
                                <span class="truncate" x-text="item.name"></span>
                                <button type="button" class="chat-file-remove" x-on:click="remove(item.ref)" x-bind:aria-label="'Take off ' + item.name"><x-icon name="x" class="size-3.5" /></button>
                            </li>
                        </template>
                        <li class="chat-file" x-show="uploading > 0" role="status"><x-icon name="loader-circle" class="size-4 animate-spin" />Uploading…</li>
                    </ul>
                    <label for="chat-box-{{ $this->getId() }}" class="sr-only">Write to your tutor</label>
                    <textarea id="chat-box-{{ $this->getId() }}" class="chat-box" rows="2" x-model="draft" x-ref="box" maxlength="8000"
                        placeholder="Write to your tutor… (Enter sends, Shift+Enter for a new line)" x-bind:disabled="live"
                        x-on:paste="paste($event)"
                        x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); submit(); }"></textarea>
                </div>
                <button type="submit" class="btn btn-primary chat-send" x-bind:disabled="busy" aria-label="Send">
                    <span x-show="! live"><x-icon name="arrow-up" class="size-5" /></span>
                    <span x-show="live" x-cloak><x-icon name="loader-circle" class="size-5 animate-spin" /></span>
                </button>
            </form>
            <p class="text-sm text-fg-muted" x-show="note !== ''" x-text="note" role="status" x-cloak></p>
            @if ($error)
                <p class="field-error" role="alert">{{ $error }}</p>
            @endif
            <p class="chat-foot">{{ $choices->tutorModel }} · {{ $spentWords }}</p>
          </div>
        @else
            <p class="text-sm text-fg-muted">This session has ended. The chat stays here to read; start a new session to go on.</p>
        @endif
    @endif
</section>
