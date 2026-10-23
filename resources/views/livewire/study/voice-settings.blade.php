{{--
    Voice typing's settings (App\Livewire\Study\VoiceSettings): the language spoken and the model. Inside the chat's
    gear (inline), or behind a button of its own (the course guide). Not a <form>: it sits inside the chat's own form,
    and a form inside a form would send the message instead of saving.
--}}
{{-- One root, so Livewire finds its tag (an @if alone starts with its block markers). --}}
<div class="contents">
@if ($inline)
    <div class="voice-settings-inline space-y-3 p-2" x-on:keydown.enter.prevent="$wire.save()">
        @include('livewire.study.partials.voice-fields', ['id' => $this->getId()])
    </div>
@else
<div class="voice-settings" x-data="{ open: false }" x-on:keydown.escape.stop="open = false" x-on:click.outside="open = false" x-on:voice-settings-saved.window="open = false">
    <button type="button" class="chat-tool-btn" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" title="Voice typing" aria-label="Voice typing">
        <x-icon name="languages" class="size-3.5" />
    </button>
    <div class="chat-model-popover voice-settings-panel" x-show="open" x-cloak role="dialog" aria-label="Voice typing">
        <div class="chat-model-popover-head">
            <span class="text-xs font-semibold text-fg">Voice typing</span>
        </div>
        <div class="space-y-3 p-2" x-on:keydown.enter.prevent="$wire.save()">
            @include('livewire.study.partials.voice-fields', ['id' => $this->getId()])
        </div>
    </div>
</div>
@endif
</div>
