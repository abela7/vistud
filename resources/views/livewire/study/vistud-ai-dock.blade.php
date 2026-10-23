{{--
    ViStud AI from any student page (App\Livewire\Study\VistudAiDock): the corner button, and the panel it opens with
    the same chat as the ViStud AI page, loaded on opening. Esc or the close button puts it away; the conversation
    stays, and the button opens it again.
--}}
<div class="ai-dock" x-data x-on:keydown.escape.window="$wire.open && $wire.hide()">
    @unless ($open)
        <button type="button" class="ai-dock-fab" wire:click="show" wire:loading.attr="aria-busy" wire:target="show" title="Ask ViStud AI">
            <x-icon name="sparkles" class="size-5 shrink-0" />
            <span class="ai-dock-label">ViStud AI</span>
        </button>
    @else
        <section class="ai-dock-panel" role="dialog" aria-modal="false" aria-labelledby="ai-dock-title">
            <header class="ai-dock-head">
                <span class="ai-dock-mark" aria-hidden="true"><x-icon name="sparkles" class="size-4" /></span>
                <h2 id="ai-dock-title" class="ai-dock-title">ViStud AI</h2>
                <div class="ai-dock-actions">
                    <button type="button" class="ai-dock-btn" wire:click="newChat" title="Start a new conversation"><x-icon name="plus" class="size-4" /><span class="sr-only">New chat</span></button>
                    <a class="ai-dock-btn" href="{{ route('vistud-ai', $conversation !== null ? ['c' => $conversation] : []) }}" title="Open the full page"><x-icon name="maximize-2" class="size-4" /><span class="sr-only">Open the full page</span></a>
                    <button type="button" class="ai-dock-btn" wire:click="hide" title="Close (Esc)"><x-icon name="x" class="size-4" /><span class="sr-only">Close</span></button>
                </div>
            </header>
            <div class="ai-dock-body">
                <livewire:workspaces.tutor-chat :vistud="true" :conversation-id="$conversation" :key="'ai-dock-chat-'.$chat" />
            </div>
        </section>
    @endunless
</div>
