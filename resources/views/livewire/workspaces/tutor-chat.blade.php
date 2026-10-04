{{--
    The built-in chat on a study session's page (App\Livewire\Workspaces\TutorChat): a box with the tutor's
    name, what the chat has cost and "Keep what the tutor marked"; the turns (the tutor's as Markdown, its marks
    as labelled quotes); and a line to write in. The tutor's answer arrives when it is whole: the box says
    "Thinking…" meanwhile.
--}}
@php
    use App\Engine\Choices;
@endphp
<section class="chat" aria-labelledby="chat-heading-{{ $this->getId() }}"
    x-data="{ stick() { const log = $refs.log; if (log) log.scrollTop = log.scrollHeight; } }"
    x-init="stick()"
    x-on:chat-turn.window="$nextTick(() => { stick(); $refs.box && $refs.box.focus(); })">
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
    @elseif ($turns === [])
        <div class="chat-empty">
            <p class="font-medium">Say hello, or ask about anything in this course.</p>
            <p class="text-sm text-fg-muted">The tutor already knows where you stand: your topics, questions, notes and earlier sessions. It looks things up rather than guessing.</p>
            @if ($open)
                <ul class="chat-chips" role="list">
                    @foreach ($suggestions as $suggestion)
                        <li><button type="button" class="chat-chip" wire:click="say(@js($suggestion))" wire:loading.attr="disabled">{{ $suggestion }}</button></li>
                    @endforeach
                </ul>
            @endif
        </div>
    @else
        <ol class="chat-log" role="list" aria-label="The chat" x-ref="log">
            @foreach ($turns as $i => $turn)
                <li wire:key="turn-{{ $i }}" @class(['chat-turn', 'is-me' => $turn['role'] === 'user', 'is-tutor' => $turn['role'] === 'assistant'])>
                    @if ($turn['role'] === 'user')
                        <p class="chat-bubble">{{ $turn['text'] }}</p>
                    @else
                        <div class="chat-bubble chat-markdown">{!! $turn['html'] !!}</div>
                        @if ($turn['tools'] !== [] || $turn['cost_micros'] > 0)
                            <p class="chat-meta">{{ implode(' · ', array_filter([
                                $turn['tools'] !== [] ? 'Looked up: '.implode(', ', array_unique($turn['tools'])) : null,
                                $turn['cost_micros'] > 0 ? ($turn['cost_micros'] >= 5_000 ? Choices::dollars($turn['cost_micros']) : 'under 1¢') : null,
                            ])) }}</p>
                        @endif
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    @if ($ready)
        @if ($open)
            <form wire:submit="send" class="chat-compose" novalidate>
                <label for="chat-box-{{ $this->getId() }}" class="sr-only">Write to your tutor</label>
                <textarea id="chat-box-{{ $this->getId() }}" class="chat-box" rows="2" wire:model="text" x-ref="box" placeholder="Write to your tutor… (Enter sends, Shift+Enter for a new line)" maxlength="8000"
                    wire:loading.attr="disabled" wire:target="send, say"
                    x-on:keydown.enter.prevent="if (!$event.shiftKey) { $wire.send() } else { const b = $el; const s = b.selectionStart; b.value = b.value.slice(0, s) + '\n' + b.value.slice(b.selectionEnd); b.selectionStart = b.selectionEnd = s + 1; b.dispatchEvent(new Event('input')) }"></textarea>
                <button type="submit" class="btn btn-primary chat-send" wire:loading.attr="disabled" wire:target="send, say" aria-label="Send">
                    <span wire:loading.remove wire:target="send, say"><x-icon name="arrow-up" class="size-5" /></span>
                    <span wire:loading wire:target="send, say"><x-icon name="loader-circle" class="size-5 animate-spin" /></span>
                </button>
            </form>
            <p class="chat-thinking text-sm text-fg-muted" wire:loading wire:target="send, say" role="status">Thinking…</p>
            @if ($error)
                <p class="field-error" role="alert">{{ $error }}</p>
            @endif
        @else
            <p class="text-sm text-fg-muted">This session has ended. The chat stays here to read; start a new session to go on.</p>
        @endif
    @endif
</section>
