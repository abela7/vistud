{{--
    The admin area's AI engine page (App\Livewire\Admin\EngineSetup): three plain steps. Get a key at the
    service, paste it here (it is tried at once), and choose the models everyone starts with. The key is
    shown again only by its last four characters.
--}}
@php
    use Illuminate\Support\Carbon;
@endphp
<div class="space-y-8">
    <div class="space-y-2">
        <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">AI engine</h1>
        <p class="max-w-2xl text-fg-muted">The built-in chat answers through a model service. Set it up once here; each student then picks their own models and limits. Nothing is sent to the service until a student writes in a chat.</p>
    </div>

    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" :tone="$noticeTone" />
    </div>

    <section class="question-panel space-y-4" aria-labelledby="engine-status-heading">
        <div class="panel-head">
            <span class="item-icon" aria-hidden="true"><x-icon name="brain" class="size-5" /></span>
            <div class="panel-head-text">
                <h2 id="engine-status-heading" class="panel-title">
                    Where it stands
                    @if ($status['key_set'])
                        <span class="badge badge-success"><x-icon name="circle-check" class="size-3.5" />Key set</span>
                    @else
                        <span class="badge badge-warning"><x-icon name="triangle-alert" class="size-3.5" />Not set up</span>
                    @endif
                </h2>
                <p class="panel-hint">
                    @if ($status['key_unreadable'])
                        A key was saved, but it can't be read any more (the app's own key changed). Paste it again.
                    @elseif ($status['key_from_env'])
                        The key comes from the server's .env file. Pasting one below replaces it.
                    @elseif ($status['key_set'])
                        Key ending {{ $status['key_hint'] }}, saved {{ Carbon::parse($status['key_updated_at'])->diffForHumans() }}. Service: {{ $status['url'] }}.
                    @else
                        No key yet. Follow the steps below; it takes a few minutes.
                    @endif
                </p>
            </div>
            @if ($status['key_set'])
                <div class="panel-head-actions">
                    <x-button size="sm" icon="play" wire:click="test" wire:loading.attr="aria-busy" wire:target="test" busy-label="Trying…">Try it</x-button>
                    <x-button size="sm" variant="danger" icon="trash-2" wire:click="askRemove">Remove the key</x-button>
                </div>
            @endif
        </div>
        @if ($result)
            <x-alert :tone="$result['ok'] ? 'success' : 'danger'" :title="$result['ok'] ? 'It works' : 'It doesn\'t work yet'">{{ $result['message'] }}</x-alert>
        @endif
        @if ($confirmingRemoval)
            <div class="plan-confirm" role="alert">
                <p class="font-medium">Remove the key? The chat stops answering until a new one is set.</p>
                <div class="flex flex-wrap gap-2">
                    <x-button variant="danger" size="sm" wire:click="removeKey">Remove it</x-button>
                    <x-button size="sm" wire:click="keepKey">Keep it</x-button>
                </div>
            </div>
        @endif
    </section>

    <section class="question-panel space-y-4" aria-labelledby="engine-key-heading">
        <div class="panel-head">
            <span class="item-icon" aria-hidden="true"><x-icon name="key-round" class="size-5" /></span>
            <div class="panel-head-text">
                <h2 id="engine-key-heading" class="panel-title">{{ $status['key_set'] ? 'Replace the key' : 'Step 1 · Get a key and paste it here' }}</h2>
                <p class="panel-hint">One key gives every model. You pay the service only for what the chats use; a study session is usually a few cents.</p>
            </div>
        </div>
        <ol class="list-decimal space-y-1 pl-5 text-sm text-fg-muted">
            <li>Make an account at <a href="https://openrouter.ai" class="text-link" target="_blank" rel="noopener">openrouter.ai</a> and add a little credit (a few dollars goes a long way).</li>
            <li>Open <strong>Keys</strong> in its settings, press <strong>Create key</strong>, and copy the key. It starts with <code>sk-or-</code>.</li>
            <li>Paste it below and press <strong>Save and try</strong>. Never paste it anywhere else, and never into a chat with an AI.</li>
        </ol>
        <form wire:submit="saveKey" novalidate class="space-y-4">
            <x-field name="key" label="The key" type="password" wire:model="key" autocomplete="off" :revealable="true" placeholder="sk-or-…" hint="Kept encrypted. Only its last four characters are ever shown again." />
            <div class="flex flex-wrap gap-2">
                <x-button type="submit" variant="primary" icon="check" wire:loading.attr="aria-busy" wire:target="saveKey" busy-label="Saving and trying…">Save and try</x-button>
            </div>
        </form>
    </section>

    <section class="question-panel space-y-4" aria-labelledby="engine-defaults-heading">
        <div class="panel-head">
            <span class="item-icon" aria-hidden="true"><x-icon name="settings" class="size-5" /></span>
            <div class="panel-head-text">
                <h2 id="engine-defaults-heading" class="panel-title">Step 2 · The models everyone starts with</h2>
                <p class="panel-hint">Students can change these for themselves. Leave a model empty to make them choose.</p>
            </div>
        </div>
        <form wire:submit="saveDefaults" novalidate class="space-y-4">
            <datalist id="engine-default-models">
                @foreach ($models as $model)
                    <option value="{{ $model->id }}">{{ $model->name }} · {{ $model->priceWords() }}</option>
                @endforeach
            </datalist>
            @if ($status['key_set'] && $models === [])
                <p class="text-sm text-fg-muted">The service's list of models couldn't be loaded, so type a model's id as the service names it.</p>
            @endif
            <div class="grid gap-4 sm:grid-cols-3">
                <x-field name="tutorModel" label="Tutor model" wire:model="tutorModel" list="engine-default-models" autocomplete="off" placeholder="openai/gpt-4.1-mini" hint="Teaches in sessions." />
                <x-field name="readerModel" label="Reader model (optional)" wire:model="readerModel" list="engine-default-models" autocomplete="off" placeholder="google/gemini-2.5-flash" hint="Reads files, writes summaries. Tutor's when empty." />
                <x-field name="helperModel" label="Helper model (optional)" wire:model="helperModel" list="engine-default-models" autocomplete="off" placeholder="google/gemini-2.5-flash-lite" hint="Quick edits and questions. Reader's when empty." />
            </div>
            <details class="text-sm">
                <summary class="cursor-pointer text-fg-muted">Another service than OpenRouter</summary>
                <div class="pt-3">
                    <x-field name="url" label="Service address" wire:model="url" autocomplete="off" placeholder="https://openrouter.ai/api/v1" hint="Any service that speaks the OpenAI chat format. Leave it as it is for OpenRouter." />
                </div>
            </details>
            <x-button type="submit" variant="primary" icon="check" wire:loading.attr="aria-busy" wire:target="saveDefaults" busy-label="Saving…">Save the defaults</x-button>
        </form>
    </section>

    <section class="question-panel space-y-4" aria-labelledby="engine-shared-heading">
        <div class="panel-head">
            <span class="item-icon" aria-hidden="true"><x-icon name="users" class="size-5" /></span>
            <div class="panel-head-text">
                <h2 id="engine-shared-heading" class="panel-title">
                    Students without a key
                    @if ($status['shared_working'])
                        <span class="badge badge-success"><x-icon name="circle-check" class="size-3.5" />On</span>
                    @else
                        <span class="badge">Off</span>
                    @endif
                </h2>
                <p class="panel-hint">Let students who haven't added their own key use one key you pay for, with one model and a daily limit each. When they add their own key, they use theirs.</p>
            </div>
        </div>
        <ol class="list-decimal space-y-1 pl-5 text-sm text-fg-muted">
            <li>At OpenRouter, make a <strong>separate key</strong> just for students and give it a <strong>credit limit</strong> (for example $10). That limit is the most it can ever cost you.</li>
            <li>Paste it below, choose the one model, and set how much each student may use a day.</li>
        </ol>
        <form wire:submit="saveShared" novalidate class="space-y-4">
            <x-field name="sharedKey" label="Key for students" type="password" wire:model="sharedKey" autocomplete="off" :revealable="true" placeholder="sk-or-…" :hint="$status['shared_key_hint'] !== null ? 'Saved, ending '.$status['shared_key_hint'].'. Leave empty to keep it.' : 'Kept encrypted. Not the admin key above.'" />
            <div class="space-y-1.5">
                <label for="field-sharedModels" class="field-label">Models students can choose</label>
                <textarea id="field-sharedModels" class="input" rows="4" wire:model="sharedModels" autocomplete="off" spellcheck="false" placeholder="google/gemini-2.5-flash-lite" aria-describedby="field-sharedModels-hint"></textarea>
                <p id="field-sharedModels-hint" class="text-sm text-fg-muted">One per line; the first is the one they start with. Only free or very cheap models are accepted (up to ${{ number_format(\App\Engine\Setup::MAX_SHARED_PRICE, 2) }} a million tokens).</p>
                @error('sharedModels')
                    <p id="field-sharedModels-error" class="field-error"><x-icon name="circle-alert" class="mt-0.5 size-4" /><span>{{ $message }}</span></p>
                @enderror
            </div>
            <x-field name="sharedDaily" label="Daily limit per student, in dollars" wire:model="sharedDaily" inputmode="decimal" autocomplete="off" placeholder="0.10" hint="Counted in each student's own day." />
            <x-checkbox name="sharedOn" label="Let students without a key use it" wire:model="sharedOn" />
            <div class="flex flex-wrap gap-2">
                <x-button type="submit" variant="primary" icon="check" wire:loading.attr="aria-busy" wire:target="saveShared" busy-label="Saving…">Save</x-button>
                @if ($status['shared_key_hint'] !== null)
                    <x-button variant="danger" icon="trash-2" wire:click="removeSharedKey">Remove the key for students</x-button>
                @endif
            </div>
        </form>
        @if ($status['shared_key_hint'] !== null)
            <details class="text-sm" @if ($cheapModels !== []) open @endif>
                <summary class="cursor-pointer text-fg-muted">Free and very cheap models this key can use ({{ count($cheapModels) }})</summary>
                @if ($cheapModels === [])
                    <p class="pt-3 text-fg-muted">The list couldn't be loaded with the students' key. Check the key at OpenRouter, then reload this page.</p>
                @else
                    <p class="pt-3 text-fg-muted">Cheapest first. Press Add, then Save. Some free models only answer students who allow their chats to be used for training (a choice in their AI settings).</p>
                    <ul class="max-h-80 space-y-1 overflow-y-auto pt-2" role="list">
                        @foreach ($cheapModels as $cheap)
                            <li class="flex flex-wrap items-center justify-between gap-2">
                                <span class="min-w-0"><span class="font-medium text-fg">{{ $cheap->name }}</span> <code class="break-all">{{ $cheap->id }}</code> · {{ $cheap->priceWords() }}</span>
                                <x-button size="sm" icon="plus" wire:click="addShared('{{ $cheap->id }}')">Add</x-button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </details>
        @endif
    </section>
</div>
