{{--
    A student's engine settings (App\Livewire\Workspaces\EngineSettings): the models for the built-in chat, by
    id, from the list the service offers (typed or picked), their prices, the spending limits, training and
    consent, in one dialog.
--}}
<div>
    <div role="status" aria-live="polite" class="empty:hidden">
        <x-toast :message="$notice" />
    </div>

    <dialog id="engine-settings-dialog" class="modal" aria-labelledby="engine-settings-title"
        wire:ignore.self
        x-data
        x-on:engine-settings-dialog-open.window="$el.open || $el.showModal()"
        x-on:engine-settings-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.editing && $wire.close()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($editing)
            <form wire:submit="save" novalidate class="modal-panel" wire:key="engine-settings-form">
                <div class="modal-head">
                    <h2 id="engine-settings-title" class="min-w-0 flex-1 text-lg font-semibold">AI engine</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>
                <div class="space-y-4 px-5 pt-2">
                    @unless ($keySet)
                        <x-alert tone="warning" title="The AI engine isn't set up yet">
                            @if ($setupUrl)
                                Set it up on the admin area's <a href="{{ $setupUrl }}" class="text-link">AI engine page</a>: paste a key once and try it. Your choices here are kept meanwhile.
                            @else
                                Ask the owner to set it up in the admin area. Until then the chat can't answer; your choices here are kept.
                            @endif
                        </x-alert>
                    @elseif ($models === [])
                        <x-alert tone="warning" title="The list of models couldn't be loaded">Type a model's id as the service names it. <code>php artisan vistud:doctor</code> says what's wrong.</x-alert>
                    @endunless
                    <p class="text-sm text-fg-muted">Any model the service offers, by its id. A cheap one does for most of a session; a stronger one for hard questions. Prices are per million tokens: a session is usually a few thousand.</p>
                    <datalist id="engine-models">
                        @foreach ($models as $model)
                            <option value="{{ $model->id }}">{{ $model->name }} · {{ $model->priceWords() }}</option>
                        @endforeach
                    </datalist>
                    <x-field name="tutorModel" label="Tutor model" wire:model.live.debounce.500ms="tutorModel" list="engine-models" autocomplete="off" placeholder="openai/gpt-4.1-mini" :hint="$tutorWords ?? 'The one that teaches in study sessions.'" autofocus />
                    <x-field name="quickModel" label="Quick model (optional)" wire:model.live.debounce.500ms="quickModel" list="engine-models" autocomplete="off" placeholder="google/gemini-2.5-flash" :hint="$quickWords ?? 'For small jobs, like folding a long chat. The tutor model when empty.'" />
                    <x-field name="fallbackModel" label="If the tutor model fails (optional)" wire:model.live.debounce.500ms="fallbackModel" list="engine-models" autocomplete="off" placeholder="anthropic/claude-haiku-4.5" :hint="$fallbackWords ?? 'Tried when the first is down or busy.'" />
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
                </div>
                <div class="modal-actions">
                    <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                    <x-button type="submit" variant="primary" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save</x-button>
                </div>
            </form>
        @endif
    </dialog>
</div>
