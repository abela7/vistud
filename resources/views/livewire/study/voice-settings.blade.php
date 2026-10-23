{{--
    Voice typing's settings beside the microphone (App\Livewire\Study\VoiceSettings): the model, and the language
    spoken. Opens over the message box, as the model menu does (.chat-model-popover).
--}}
<div class="voice-settings" x-data="{ open: false }" x-on:keydown.escape.stop="open = false" x-on:click.outside="open = false" x-on:voice-settings-saved.window="open = false">
    <button type="button" class="chat-tool-btn" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" title="Voice typing settings" aria-label="Voice typing settings">
        <x-icon name="languages" class="size-3.5" />
    </button>
    <div class="chat-model-popover voice-settings-panel" x-show="open" x-cloak role="dialog" aria-label="Voice typing settings">
        <div class="chat-model-popover-head">
            <span class="text-xs font-semibold text-fg">Voice typing</span>
        </div>
        <form wire:submit="save" class="space-y-3 p-2" novalidate>
            <div class="space-y-1">
                <label for="voice-language-{{ $this->getId() }}" class="field-label">I'll speak</label>
                <select id="voice-language-{{ $this->getId() }}" class="input" wire:model="language">
                    @foreach (\App\Engine\Dictation::SPOKEN as $code => $name)
                        <option value="{{ $code }}">{{ $name }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-fg-muted">Choose it, so Amharic isn't taken for Arabic.</p>
                @error('language') <p class="field-error"><span>{{ $message }}</span></p> @enderror
            </div>
            <div class="space-y-1">
                <label for="voice-model-{{ $this->getId() }}" class="field-label">Model</label>
                <select id="voice-model-{{ $this->getId() }}" class="input" wire:model="model">
                    @foreach (\App\Engine\Dictation::MODELS as $id => $about)
                        <option value="{{ $id }}">{{ $about['name'] }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-fg-muted">{{ \App\Engine\Dictation::MODELS[$model]['hint'] ?? '' }}</p>
                @error('model') <p class="field-error"><span>{{ $message }}</span></p> @enderror
            </div>
            <x-button type="submit" size="sm" variant="primary" icon="check" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save</x-button>
        </form>
    </div>
</div>
