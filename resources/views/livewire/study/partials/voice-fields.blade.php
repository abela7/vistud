{{-- Voice typing's two choices and Save (livewire/study/voice-settings.blade.php): a button, not a form's submit. --}}
<div class="space-y-1">
    <label for="voice-language-{{ $id }}" class="field-label">Language</label>
    <select id="voice-language-{{ $id }}" class="input" wire:model="language">
        @foreach (\App\Engine\Dictation::SPOKEN as $code => $name)
            <option value="{{ $code }}">{{ $name }}</option>
        @endforeach
    </select>
    @error('language') <p class="field-error"><span>{{ $message }}</span></p> @enderror
</div>
<div class="space-y-1">
    <label for="voice-model-{{ $id }}" class="field-label">Model</label>
    <select id="voice-model-{{ $id }}" class="input" wire:model="model">
        @foreach ($models as $value => $about)
            <option value="{{ $value }}">{{ $about['name'] }}</option>
        @endforeach
    </select>
    @error('model') <p class="field-error"><span>{{ $message }}</span></p> @enderror
</div>
<x-button type="button" size="sm" variant="primary" icon="check" wire:click="save" wire:loading.attr="aria-busy" wire:target="save" busy-label="Saving…">Save</x-button>
<p class="text-xs text-success" x-data="{ shown: false }" x-on:voice-settings-saved.window="shown = true; setTimeout(() => shown = false, 2500)" x-show="shown" x-cloak role="status">Saved.</p>
