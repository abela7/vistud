{{--
    The built-in chat on a study session's page (App\Livewire\Workspaces\TutorChat): a box with the tutor's
    name, what the chat has cost and "Keep what the tutor marked"; the turns (the tutor's as Markdown, its marks
    as labelled quotes); and a line to write in. The student's words show at once; the tutor's answer streams
    in as it is written (wire:stream), with what it is looking up meanwhile. When the engine failed on the last
    message, "Try again" answers it without sending it twice. Notes and files from the module can be attached to
    a message; a file or picture can be uploaded, pasted or dropped into the box (resources/js/tutor-chat.js).
--}}
@php
    use App\Engine\Choices;
@endphp
<section class="chat" aria-labelledby="chat-heading-{{ $this->getId() }}"
    x-data="tutorChat(@js(['upload' => $upload]))"
    x-on:chat-done.window="done($event.detail.restore)">
    <div class="panel-head chat-head">
        <span class="item-icon ws-colour-purple" aria-hidden="true"><x-icon name="sparkles" class="size-5" /></span>
        <div class="panel-head-text">
            <h2 id="chat-heading-{{ $this->getId() }}" class="panel-title">Your tutor</h2>
            <p class="panel-hint">{{ $ready ? $choices->tutorModel.' · '.$spentWords : 'Not set up yet' }}</p>
        </div>
        @if ($marked)
            <div class="panel-head-actions">
                <x-button size="sm" icon="bookmark" wire:click="keep">Keep what the tutor marked</x-button>
            </div>
        @endif
    </div>

    @if (! $ready)
        <div class="chat-empty">
            <p class="font-medium">Set the AI engine up first</p>
            <p class="text-sm text-fg-muted">Your key, the model that tutors you, and your consent: one page, a few minutes.</p>
            <a href="{{ route('engine.settings') }}" class="btn btn-primary"><x-icon name="brain" class="size-4" />AI engine settings</a>
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
        <ol class="chat-log" role="list" aria-label="The chat" x-ref="log"
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
                            <div class="chat-bubble chat-markdown">{!! $turn['html'] !!}</div>
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
            @if ($waiting)
                <div class="chat-retry" x-show="! live">
                    <p class="text-sm text-fg-muted">The tutor hasn't answered your last message.</p>
                    <x-button size="sm" icon="rotate-ccw" x-on:click="retry()">Try again</x-button>
                </div>
            @endif
            <form class="chat-compose" x-on:submit.prevent="submit()" x-on:dragover.prevent x-on:drop.prevent="drop($event)" novalidate>
                <div class="chat-attach" x-on:keydown.escape.stop="picking = false" x-on:click.outside="picking = false">
                    <button type="button" class="btn btn-ghost chat-attach-button" x-on:click="picking = ! picking" x-bind:aria-expanded="picking.toString()"
                        aria-controls="chat-picker-{{ $this->getId() }}" aria-label="Attach a note, a file or a picture" x-bind:disabled="busy">
                        <x-icon name="paperclip" class="size-5" />
                    </button>
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
                            <p class="text-xs text-fg-muted">{{ $sees ? 'You can also paste a screenshot into the box, or drop files on it.' : 'Your tutor model can\'t see pictures: choose one that can in the AI engine settings.' }}</p>
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
        @else
            <p class="text-sm text-fg-muted">This session has ended. The chat stays here to read; start a new session to go on.</p>
        @endif
    @endif
</section>
