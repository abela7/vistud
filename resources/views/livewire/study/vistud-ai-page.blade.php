{{--
    ViStud AI's page (App\Livewire\Study\VistudAiPage): one workspace the height of the window. A slim bar (the
    conversations, ViStud AI and the conversation open, New chat, Full screen), then the conversations beside the chat
    from a laptop up (they fold away), sliding in over it below that (resources/js/vistud-ai.js). The chat is the same as
    a session's, in its ViStud AI mode; on a wide screen its messages and box keep a width that reads well.
--}}
<div class="vistud-ai" x-data="vistudAi" x-bind:class="{ 'is-fullscreen': fullscreen, 'is-list-open': listOpen }"
    x-on:keydown.escape.window="escape($event)">
    <header class="vistud-ai-bar">
        <button type="button" class="vistud-ai-icon-btn" x-on:click="listOpen = ! listOpen" x-bind:aria-expanded="listOpen.toString()" aria-controls="vistud-ai-list"
            x-bind:title="listOpen ? 'Hide your conversations' : 'Show your conversations'">
            <x-icon name="panel-left" class="size-5" />
            <span class="sr-only">Your conversations</span>
        </button>
        <span class="vistud-ai-mark" aria-hidden="true"><x-icon name="sparkles" class="size-4" /></span>
        <div class="vistud-ai-names">
            <h1 class="vistud-ai-name">ViStud AI</h1>
            <p class="vistud-ai-current" title="{{ $title }}">{{ $title }}</p>
        </div>
        <div class="vistud-ai-actions">
            <x-button size="sm" variant="primary" icon="plus" wire:click="newChat" x-on:click="picked()" title="Start a new conversation"><span class="vistud-ai-label">New chat</span></x-button>
            <button type="button" class="vistud-ai-icon-btn" x-on:click="fullscreen = ! fullscreen" x-bind:aria-pressed="fullscreen.toString()" x-bind:title="fullscreen ? 'Leave full screen (Esc)' : 'Full screen'">
                <x-icon name="maximize-2" class="size-4" x-show="! fullscreen" />
                <x-icon name="minimize-2" class="size-4" x-show="fullscreen" x-cloak />
                <span class="sr-only" x-text="fullscreen ? 'Leave full screen' : 'Full screen'">Full screen</span>
            </button>
        </div>
    </header>

    <div class="vistud-ai-body">
        {{-- Below a laptop the list slides in over the chat; a tap beside it closes it. --}}
        <div class="vistud-ai-scrim" x-show="listOpen && ! wide" x-cloak x-on:click="listOpen = false" aria-hidden="true"></div>

        <aside id="vistud-ai-list" class="vistud-ai-side" aria-label="Your conversations" x-show="listOpen" x-cloak
            x-transition:enter="vistud-ai-slide" x-transition:enter-start="is-out" x-transition:leave="vistud-ai-slide" x-transition:leave-end="is-out">
            <div class="vistud-ai-side-head">
                <h2 class="vistud-ai-side-title">Conversations</h2>
                <button type="button" class="vistud-ai-icon-btn vistud-ai-side-close" x-on:click="listOpen = false" title="Close"><x-icon name="x" class="size-4" /><span class="sr-only">Close your conversations</span></button>
            </div>
            <div class="vistud-ai-search">
                <x-icon name="search" class="size-4" />
                <label for="vistud-ai-search" class="sr-only">Find a conversation</label>
                <input id="vistud-ai-search" type="search" class="vistud-ai-search-input" wire:model.live.debounce.300ms="search" placeholder="Find a conversation" autocomplete="off" />
            </div>
            @if ($conversations === [])
                <p class="vistud-ai-list-empty">{{ $search !== '' ? 'No conversation is called that.' : 'Your conversations show here. Ask something to start one.' }}</p>
            @else
                <ul class="vistud-ai-items" role="list">
                    @foreach ($conversations as $item)
                        <li wire:key="conversation-{{ $item['id'] }}" @class(['vistud-ai-item', 'is-current' => $item['id'] === $conversation])>
                            <button type="button" class="vistud-ai-open" wire:click="open('{{ $item['id'] }}')" x-on:click="picked()" @if ($item['id'] === $conversation) aria-current="true" @endif>
                                <span class="vistud-ai-title">{{ $item['title'] }}</span>
                                <span class="vistud-ai-when">{{ \Illuminate\Support\Carbon::parse($item['updated_at'], 'UTC')->diffForHumans() }}</span>
                            </button>
                            <button type="button" class="memory-action is-danger" wire:click="delete('{{ $item['id'] }}')" wire:confirm="Delete this conversation? What it cost stays counted." aria-label="Delete: {{ $item['title'] }}" title="Delete"><x-icon name="trash-2" class="size-4" /></button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </aside>

        <div class="vistud-ai-chat">
            <livewire:workspaces.tutor-chat :vistud="true" :conversation-id="$conversation" :key="'vistud-chat-'.$chat" />
        </div>
    </div>
</div>
