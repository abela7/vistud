{{--
    ViStud AI from any student page (App\Livewire\Study\VistudAiDock): the corner button, and the panel it opens with
    the same chat as the ViStud AI page, loaded on opening, and its conversations list (the button at the top left).
    Esc or the close button puts it away; the conversation stays, and the button opens it again.
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
                <button type="button" class="ai-dock-btn" wire:click="toggleList" aria-pressed="{{ $listing ? 'true' : 'false' }}" title="{{ $listing ? 'Back to the chat' : 'Your conversations' }}">
                    <x-icon name="panel-left" class="size-4" /><span class="sr-only">{{ $listing ? 'Back to the chat' : 'Your conversations' }}</span>
                </button>
                <span class="ai-dock-mark" aria-hidden="true"><x-icon name="sparkles" class="size-4" /></span>
                <h2 id="ai-dock-title" class="ai-dock-title">{{ $listing ? 'Conversations' : 'ViStud AI' }}</h2>
                <div class="ai-dock-actions">
                    <button type="button" class="ai-dock-btn" wire:click="newChat" title="Start a new conversation"><x-icon name="plus" class="size-4" /><span class="sr-only">New chat</span></button>
                    <a class="ai-dock-btn" href="{{ route('vistud-ai', $conversation !== null ? ['c' => $conversation] : []) }}" title="Open the full page"><x-icon name="maximize-2" class="size-4" /><span class="sr-only">Open the full page</span></a>
                    <button type="button" class="ai-dock-btn" wire:click="hide" title="Close (Esc)"><x-icon name="x" class="size-4" /><span class="sr-only">Close</span></button>
                </div>
            </header>
            @if ($listing)
                {{-- The conversations, as on the ViStud AI page: find one by its title, open it. --}}
                <div class="ai-dock-list">
                    <div class="vistud-ai-search">
                        <x-icon name="search" class="size-4" />
                        <label for="ai-dock-search" class="sr-only">Find a conversation</label>
                        <input id="ai-dock-search" type="search" class="vistud-ai-search-input" wire:model.live.debounce.300ms="search" placeholder="Find a conversation" autocomplete="off" />
                    </div>
                    @if ($conversations === [])
                        <p class="vistud-ai-list-empty">{{ trim($search) !== '' ? 'No conversation is called that.' : 'Your conversations show here. Ask something to start one.' }}</p>
                    @else
                        <ul class="vistud-ai-items" role="list">
                            @foreach ($conversations as $item)
                                <li wire:key="dock-conversation-{{ $item['id'] }}" @class(['vistud-ai-item', 'is-current' => $item['id'] === $conversation])>
                                    <button type="button" class="vistud-ai-open" wire:click="pick('{{ $item['id'] }}')" @if ($item['id'] === $conversation) aria-current="true" @endif>
                                        <span class="vistud-ai-title">{{ $item['title'] }}</span>
                                        <span class="vistud-ai-when">{{ \Illuminate\Support\Carbon::parse($item['updated_at'], 'UTC')->diffForHumans() }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @else
                <div class="ai-dock-body">
                    <livewire:workspaces.tutor-chat :vistud="true" :conversation-id="$conversation" :key="'ai-dock-chat-'.$chat" />
                </div>
            @endif
        </section>
    @endunless
</div>
