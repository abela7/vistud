{{--
    A student's AI engine page (App\Livewire\Study\EngineSettings): their own key (pasted once, tried at
    once, shown again only by its last four characters), the models they choose from the service's list with
    prices, their limits, training and consent.
--}}
@php
    use Illuminate\Support\Carbon;
@endphp
<div class="space-y-8">
    <div class="space-y-2">
        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">AI engine</h1>
        <p class="max-w-2xl text-fg-muted">The built-in chat in your study sessions answers through a model service. Set it up once here. Nothing is sent to the service until you write in a chat.</p>
    </div>

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>

    <section class="question-panel space-y-4" aria-labelledby="engine-key-heading">
        <div class="panel-head">
            <span class="item-icon" aria-hidden="true"><x-icon name="key-round" class="size-5" /></span>
            <div class="panel-head-text">
                <h2 id="engine-key-heading" class="panel-title">
                    Your key
                    @if ($keyState === 'own')
                        <span class="badge badge-success"><x-icon name="circle-check" class="size-3.5" />Set</span>
                    @elseif ($keyState === 'shared')
                        <span class="badge"><x-icon name="users" class="size-3.5" />Using the owner's</span>
                    @else
                        <span class="badge badge-warning"><x-icon name="triangle-alert" class="size-3.5" />Not set up</span>
                    @endif
                </h2>
                <p class="panel-hint">
                    @if ($keyState === 'own')
                        Your key ending {{ $choices->ownKeyHint }}, saved {{ Carbon::parse($choices->keyUpdatedAt)->diffForHumans() }}. Your chats go on your own account at the service.
                    @elseif ($keyState === 'shared')
                        The owner set up a key for everyone, so your chats work already. Paste your own below to pay for your own chats instead.
                    @else
                        No key yet. Follow the three steps below; it takes a few minutes.
                    @endif
                </p>
            </div>
            @if ($keyState !== 'none')
                <div class="panel-head-actions">
                    <x-button size="sm" icon="play" wire:click="test" wire:loading.attr="aria-busy" wire:target="test" busy-label="Trying…">Try it</x-button>
                    @if ($keyState === 'own')
                        <x-button size="sm" variant="danger" icon="trash-2" wire:click="askRemove">Remove</x-button>
                    @endif
                </div>
            @endif
        </div>
        @if ($result)
            <x-alert :tone="$result['ok'] ? 'success' : 'danger'" :title="$result['ok'] ? 'It works' : 'It doesn\'t work yet'">{{ $result['message'] }}</x-alert>
        @endif
        @if ($confirmingRemoval)
            <div class="plan-confirm" role="alert">
                <p class="font-medium">Remove your key? {{ $setupUrl !== null || $keyState === 'own' ? 'Your chats stop answering unless the owner set up a key for everyone.' : '' }}</p>
                <div class="flex flex-wrap gap-2">
                    <x-button variant="danger" size="sm" wire:click="removeKey">Remove it</x-button>
                    <x-button size="sm" wire:click="keepKey">Keep it</x-button>
                </div>
            </div>
        @endif
        <ol class="list-decimal space-y-1 pl-5 text-sm text-fg-muted">
            <li>Make an account at <a href="https://openrouter.ai" class="text-link" target="_blank" rel="noopener">openrouter.ai</a> and add a little credit. A study session usually costs a few cents, so a few dollars goes a long way.</li>
            <li>Open <strong>Keys</strong> in its settings, press <strong>Create key</strong>, and copy the key. It starts with <code>sk-or-</code>.</li>
            <li>Paste it below and press <strong>Save and try</strong>. Never paste it anywhere else, and never into a chat with an AI.</li>
        </ol>
        <form wire:submit="saveKey" novalidate class="space-y-4">
            <x-field name="key" :label="$keyState === 'own' ? 'Replace your key' : 'Your key'" type="password" wire:model="key" autocomplete="off" :revealable="true" placeholder="sk-or-…" hint="Kept encrypted. Only its last four characters are ever shown again." />
            <x-button type="submit" variant="primary" icon="check" wire:loading.attr="aria-busy" wire:target="saveKey" busy-label="Saving and trying…">Save and try</x-button>
        </form>
        @if ($keyState === 'none' && $setupUrl)
            <p class="text-sm text-fg-muted">As an admin you can also set up <a href="{{ $setupUrl }}" class="text-link">one key for everyone</a>.</p>
        @endif
    </section>

    <form wire:submit="save" novalidate class="space-y-8">
        <section class="question-panel space-y-4" aria-labelledby="engine-models-heading">
            <div class="panel-head">
                <span class="item-icon" aria-hidden="true"><x-icon name="brain" class="size-5" /></span>
                <div class="panel-head-text">
                    <h2 id="engine-models-heading" class="panel-title">Your models</h2>
                    <p class="panel-hint">Any model the service offers, by its id. A cheap one does for most of a session; a stronger one for hard questions. Prices are per million tokens; a session is usually a few thousand.</p>
                </div>
            </div>
            @if ($keyState !== 'none' && $models === [])
                <p class="text-sm text-fg-muted">The service's list of models couldn't be loaded, so type a model's id as the service names it. <em>Try it</em> above says what's wrong.</p>
            @endif
            <datalist id="engine-models">
                @foreach ($models as $model)
                    <option value="{{ $model->id }}">{{ $model->name }} · {{ $model->priceWords() }}</option>
                @endforeach
            </datalist>
            <x-field name="tutorModel" label="Tutor model" wire:model.live.debounce.500ms="tutorModel" list="engine-models" autocomplete="off" placeholder="openai/gpt-4.1-mini" :hint="$tutorWords ?? 'The one that teaches in study sessions.'" />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="readerModel" label="Reader model (optional)" wire:model.live.debounce.500ms="readerModel" list="engine-models" autocomplete="off" placeholder="google/gemini-2.5-flash" :hint="$readerWords ?? 'Reads files, writes summaries. The tutor\'s when empty.'" />
                <x-field name="helperModel" label="Helper model (optional)" wire:model.live.debounce.500ms="helperModel" list="engine-models" autocomplete="off" placeholder="google/gemini-2.5-flash-lite" :hint="$helperWords ?? 'Quick edits and questions. The reader\'s when empty.'" />
            </div>
            <x-field name="fallbackModel" label="If the tutor model fails (optional)" wire:model.live.debounce.500ms="fallbackModel" list="engine-models" autocomplete="off" placeholder="anthropic/claude-haiku-4.5" :hint="$fallbackWords ?? 'Tried when the first is down or busy.'" />
        </section>

        <section class="question-panel space-y-4" aria-labelledby="engine-language-heading">
            <div class="panel-head">
                <span class="item-icon" aria-hidden="true"><x-icon name="languages" class="size-5" /></span>
                <div class="panel-head-text">
                    <h2 id="engine-language-heading" class="panel-title">Your language</h2>
                    <p class="panel-hint">The tutor teaches in it, whatever language you write in. The course's own terms stay as they are, with a translation beside them when it helps.</p>
                </div>
            </div>
            <datalist id="engine-languages">
                @foreach (['English', 'Amharic', 'Afaan Oromo', 'Tigrinya', 'Somali', 'Arabic', 'French', 'Spanish', 'Portuguese', 'Swahili', 'Hindi', 'Chinese'] as $name)
                    <option value="{{ $name }}"></option>
                @endforeach
            </datalist>
            <x-field name="language" label="Teach me in (optional)" wire:model="language" list="engine-languages" autocomplete="off" placeholder="Amharic" hint="Empty: the language you write in." />
            <label class="flex items-start gap-3">
                <input type="checkbox" class="mt-1" wire:model="askTopics" aria-describedby="engine-ask-topics-hint">
                <span>
                    <span class="font-medium">Ask me before adding or switching topics</span>
                    <span id="engine-ask-topics-hint" class="block text-sm text-fg-muted">Off: the tutor finds the topics in what you study, adds them to the module and moves between them as it teaches, and tells you in a line.</span>
                </span>
            </label>
        </section>

        <section class="question-panel space-y-4" aria-labelledby="engine-limits-heading">
            <div class="panel-head">
                <span class="item-icon" aria-hidden="true"><x-icon name="shield-check" class="size-5" /></span>
                <div class="panel-head-text">
                    <h2 id="engine-limits-heading" class="panel-title">Your limits and privacy</h2>
                    <p class="panel-hint">The chat stops when a limit is reached and tells you; raise it here any time.</p>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="sessionCap" label="Most a session may cost ($)" type="text" inputmode="decimal" wire:model="sessionCap" hint="0 for no limit." />
                <x-field name="monthCap" label="Most a month may cost ($)" type="text" inputmode="decimal" wire:model="monthCap" hint="0 for no limit." />
            </div>
            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" class="checkbox mt-0.5" wire:model="noTraining">
                <span>Keep my words out of model training (only providers that promise this are used).</span>
            </label>
            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" class="checkbox mt-0.5" wire:model="consent">
                <span>I agree that the chat sends my study material (the briefing, my notes and files when the tutor reads them) to the model's provider. Nothing is sent until I write in the chat.</span>
            </label>
            <x-button type="submit" variant="primary" icon="check" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save</x-button>
        </section>
    </form>
</div>
