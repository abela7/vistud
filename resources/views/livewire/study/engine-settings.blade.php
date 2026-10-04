{{--
    A student's AI settings (App\Livewire\Study\EngineSettings): their own key (pasted once, tried at once, shown
    again only by its last four characters), a model for each of the three roles with its price or what it does, what
    the month has cost by role, how the tutor works (language and the trust toggles), limits and consent. Hints are one
    line (DESIGN.md §5.4).
--}}
@php
    use Illuminate\Support\Carbon;
@endphp
<div class="space-y-8">
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
                        Your key ending {{ $choices->ownKeyHint }} · saved {{ Carbon::parse($choices->keyUpdatedAt)->diffForHumans() }}
                    @elseif ($keyState === 'shared')
                        The owner's key is in use. Paste your own to pay yourself.
                    @else
                        No key yet. Three steps, a few minutes.
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
        @if ($keyState !== 'own')
        <ol class="list-decimal space-y-1 pl-5 text-sm text-fg-muted">
            <li>Make an account at <a href="https://openrouter.ai" class="text-link" target="_blank" rel="noopener">openrouter.ai</a> and add a little credit. A study session usually costs a few cents, so a few dollars goes a long way.</li>
            <li>Open <strong>Keys</strong> in its settings, press <strong>Create key</strong>, and copy the key. It starts with <code>sk-or-</code>.</li>
            <li>Paste it below and press <strong>Save and try</strong>. Never paste it anywhere else, and never into a chat with an AI.</li>
        </ol>
        @endif
        <form wire:submit="saveKey" novalidate class="space-y-4">
            <x-field name="key" :label="$keyState === 'own' ? 'Replace your key' : 'Your key'" type="password" wire:model="key" autocomplete="off" :revealable="true" placeholder="sk-or-…" hint="Kept encrypted. Only its last four characters are ever shown again." />
            <x-button type="submit" variant="primary" icon="check" wire:loading.attr="aria-busy" wire:target="saveKey" busy-label="Saving and trying…">Save and try</x-button>
        </form>
        @if ($keyState === 'none' && $setupUrl)
            <p class="text-sm text-fg-muted">As an admin you can also set up <a href="{{ $setupUrl }}" class="text-link">one key for everyone</a>.</p>
        @endif
    </section>

    <section class="question-panel space-y-4" aria-labelledby="engine-usage-heading">
        <div class="panel-head">
            <span class="item-icon" aria-hidden="true"><x-icon name="trending-up" class="size-5" /></span>
            <div class="panel-head-text">
                <h2 id="engine-usage-heading" class="panel-title">Usage this month</h2>
                <p class="panel-hint">
                    @if ($choices->monthCapMicros > 0)
                        {{ $choices->spent($usage['total']) }} of {{ $choices::dollars($choices->monthCapMicros) }}, all three together
                    @else
                        No monthly limit
                    @endif
                </p>
            </div>
        </div>
        <dl class="grid gap-3 sm:grid-cols-3" data-usage>
            @foreach ($roles as $role)
                <div class="usage-tile">
                    <dt>{{ $role->label() }}</dt>
                    <dd data-usage-role="{{ $role->value }}">{{ $choices->spent($usage[$role->value]) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <form wire:submit="save" novalidate class="space-y-8">
        <section class="question-panel space-y-4" aria-labelledby="engine-models-heading">
            <div class="panel-head">
                <span class="item-icon" aria-hidden="true"><x-icon name="brain" class="size-5" /></span>
                <div class="panel-head-text">
                    <h2 id="engine-models-heading" class="panel-title">Your models</h2>
                    <p class="panel-hint">Any model the service offers, by its id.</p>
                </div>
            </div>
            @if ($keyState !== 'none' && $models === [])
                <p class="text-sm text-fg-muted">The list of models didn't load. Type an id as the service names it; <em>Try it</em> says what's wrong.</p>
            @endif
            <datalist id="engine-models">
                @foreach ($models as $model)
                    <option value="{{ $model->id }}">{{ $model->name }} · {{ $model->priceWords() }}</option>
                @endforeach
            </datalist>
            <x-field name="tutorModel" label="Tutor" wire:model.live.debounce.500ms="tutorModel" list="engine-models" autocomplete="off" placeholder="openai/gpt-4.1-mini" :hint="$tutorWords ?? $roles[0]->does()" />
            <x-field name="readerModel" label="Reader (optional)" wire:model.live.debounce.500ms="readerModel" list="engine-models" autocomplete="off" placeholder="google/gemini-2.5-flash" :hint="$readerWords ?? $roles[1]->does()" />
            <x-field name="helperModel" label="Helper (optional)" wire:model.live.debounce.500ms="helperModel" list="engine-models" autocomplete="off" placeholder="google/gemini-2.5-flash-lite" :hint="$helperWords ?? $roles[2]->does()" />
            <x-field name="fallbackModel" label="If the tutor fails (optional)" wire:model.live.debounce.500ms="fallbackModel" list="engine-models" autocomplete="off" placeholder="anthropic/claude-haiku-4.5" :hint="$fallbackWords ?? 'Tried when the tutor is down or busy.'" />
            <p class="text-sm text-fg-muted">Empty roles use the one above: the helper the reader, the reader the tutor.</p>
        </section>

        <section class="question-panel space-y-2" aria-labelledby="engine-tutor-heading">
            <div class="panel-head">
                <span class="item-icon" aria-hidden="true"><x-icon name="languages" class="size-5" /></span>
                <div class="panel-head-text">
                    <h2 id="engine-tutor-heading" class="panel-title">How the tutor works</h2>
                    <p class="panel-hint">Your language, and what it may do without asking.</p>
                </div>
            </div>
            <datalist id="engine-languages">
                @foreach (['English', 'Amharic', 'Afaan Oromo', 'Tigrinya', 'Somali', 'Arabic', 'French', 'Spanish', 'Portuguese', 'Swahili', 'Hindi', 'Chinese'] as $name)
                    <option value="{{ $name }}"></option>
                @endforeach
            </datalist>
            <x-field name="language" label="Teach me in (optional)" wire:model="language" list="engine-languages" autocomplete="off" placeholder="Amharic" hint="Empty: the language you write in." />
            <x-checkbox name="askTopics" label="Ask me before adding or switching topics" hint="Off: the tutor keeps them and says so in a line." wire:model="askTopics" />
            <x-checkbox name="tutorMarksTopics" label="Let the tutor mark topics" hint="It marks what you've covered. You can undo it." wire:model="tutorMarksTopics" />
            <x-checkbox name="autoReadFiles" label="Read my files automatically" hint="Each file you add is read once, for its topics." wire:model="autoReadFiles" />
            <x-checkbox name="copyPasteAi" label="I use another AI by copy-paste" hint="Shows the copy and paste tools for it." wire:model="copyPasteAi" />
        </section>

        <section class="question-panel space-y-4" aria-labelledby="engine-limits-heading">
            <div class="panel-head">
                <span class="item-icon" aria-hidden="true"><x-icon name="shield-check" class="size-5" /></span>
                <div class="panel-head-text">
                    <h2 id="engine-limits-heading" class="panel-title">Limits and privacy</h2>
                    <p class="panel-hint">The AI stops at a limit and says so.</p>
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="sessionCap" label="Most a session may cost ($)" type="text" inputmode="decimal" wire:model="sessionCap" hint="0 for no limit." />
                <x-field name="monthCap" label="Most a month may cost ($)" type="text" inputmode="decimal" wire:model="monthCap" hint="0 for no limit." />
            </div>
            <x-checkbox name="noTraining" label="Keep my words out of model training" hint="Only providers that promise this are used." wire:model="noTraining" />
            <x-checkbox name="consent" label="Send my study material to the model's provider" hint="Only when I use the AI. Nothing is sent before." wire:model="consent" />
            <x-button type="submit" variant="primary" icon="check" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save</x-button>
        </section>
    </form>
</div>
