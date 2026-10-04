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
</div>
