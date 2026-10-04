{{--
    The built-in chat on a study session's page (App\Livewire\Workspaces\TutorChat): a box with the tutor's
    name, what the chat has cost and "Keep what the tutor marked"; the turns (the tutor's as Markdown, its marks
    as labelled quotes); and a line to write in. The student's words show at once; the tutor's answer streams
    in as it is written (wire:stream), with what it is looking up meanwhile. When the engine failed on the last
    message, "Try again" answers it without sending it twice.
--}}
@php
    use App\Engine\Choices;
@endphp
<section class="chat" aria-labelledby="chat-heading-{{ $this->getId() }}"
    x-data="{
        draft: '',
        pending: '',
        live: false,
        follow: true,
        stick() { const log = this.$refs.log; if (log && this.follow) log.scrollTop = log.scrollHeight; },
        submit() {
            const words = this.draft.trim();
            if (words === '' || this.live) return;
            [this.pending, this.draft, this.live, this.follow] = [words, '', true, true];
            this.$wire.send(words);
        },
        say(words) {
            if (this.live) return;
            [this.pending, this.live, this.follow] = [words, true, true];
            this.$wire.say(words);
        },
        retry() {
            if (this.live) return;
            [this.live, this.follow] = [true, true];
            this.$wire.retry();
        },
        done(restore) {
            [this.pending, this.live] = ['', false];
            if (restore) this.draft = restore;
            this.$nextTick(() => { this.stick(); this.$refs.box && this.$refs.box.focus(); });
        },
    }"
    x-init="stick(); $refs.log && new MutationObserver(() => stick()).observe($refs.log, { childList: true, subtree: true, characterData: true })"
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
                        <p class="chat-bubble">{{ $turn['text'] }}</p>
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
            <li wire:key="live-me" class="chat-turn is-me" x-show="pending !== ''" x-cloak>
                <p class="chat-bubble" x-text="pending"></p>
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
            <form class="chat-compose" x-on:submit.prevent="submit()" novalidate>
                <label for="chat-box-{{ $this->getId() }}" class="sr-only">Write to your tutor</label>
                <textarea id="chat-box-{{ $this->getId() }}" class="chat-box" rows="2" x-model="draft" x-ref="box" maxlength="8000"
                    placeholder="Write to your tutor… (Enter sends, Shift+Enter for a new line)" x-bind:disabled="live"
                    x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); submit(); }"></textarea>
                <button type="submit" class="btn btn-primary chat-send" x-bind:disabled="live" aria-label="Send">
                    <span x-show="! live"><x-icon name="arrow-up" class="size-5" /></span>
                    <span x-show="live" x-cloak><x-icon name="loader-circle" class="size-5 animate-spin" /></span>
                </button>
            </form>
            @if ($error)
                <p class="field-error" role="alert">{{ $error }}</p>
            @endif
        @else
            <p class="text-sm text-fg-muted">This session has ended. The chat stays here to read; start a new session to go on.</p>
        @endif
    @endif
</section>
