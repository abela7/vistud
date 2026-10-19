{{--
    Connected AI apps (App\Livewire\Study\Connections; docs/specs/mcp-connections-plan.md M6.1).
--}}
<div>
    @if ($student)
    <x-accordion id="connections" title="Connected AI apps" icon="link" :open="true">
        <x-slot:status>
            @if ($groups === [])
                None connected
            @else
                {{ array_sum(array_map('count', $groups)) }} connected
            @endif
        </x-slot:status>

        <p class="acc-note">Claude, ChatGPT or Gemini can study with you from their own apps. They sign in here, and what they save lands in ViStud.</p>

        @if ($flash)
            <p class="settings-note" role="status"><x-icon name="info" class="size-4" />{{ $flash }}</p>
        @endif

        @forelse ($groups as $provider => $connections)
            <div class="space-y-2">
                <p class="text-sm font-medium">{{ \App\Mcp\Provider::name($provider) }}</p>
                <ul class="item-list" role="list">
                    @foreach ($connections as $connection)
                        <li class="item-row" wire:key="connection-{{ $connection->id }}">
                            <span class="item-icon" aria-hidden="true"><x-icon name="link" class="size-5" /></span>
                            <div class="min-w-0 flex-1 space-y-0.5">
                                <p class="font-medium">{{ $connection->redirectHost ?? 'Unknown address' }}</p>
                                <p class="item-meta">
                                    {{ $connection->levelLabel() }} ·
                                    @if ($connection->lastUsedAt)
                                        last used {{ \Illuminate\Support\Carbon::parse($connection->lastUsedAt, 'UTC')->diffForHumans() }}
                                    @else
                                        not used yet
                                    @endif
                                    @if ($connection->stale())
                                        · unused for a while; it will be cut off after {{ config('mcp.revoke_days') }} days
                                    @endif
                                </p>
                            </div>
                            @if ($connection->level !== 'read')
                                <x-button variant="ghost" size="sm" wire:click="chooseUndo('{{ $connection->id }}')">Undo what it did</x-button>
                            @endif
                            <x-button variant="ghost" size="sm" wire:click="disconnect('{{ $connection->id }}')" wire:confirm="Disconnect this app? It will have to sign in again.">Disconnect</x-button>
                        </li>
                        @if ($undoFor === $connection->id)
                            @include('livewire.study.partials.connection-undo')
                        @endif
                    @endforeach
                </ul>
            </div>
        @empty
            <ol class="settings-steps">
                <li>Add the connector on your computer first (Claude: Settings › Connectors › Add custom connector). Your phone picks it up from there.</li>
                <li>Use this address: <code class="select-all">{{ $url }}</code></li>
                <li>Sign in to ViStud when it asks, and choose what it may do.</li>
            </ol>
        @endforelse

        <details class="text-sm" data-test="install-help">
            <summary class="cursor-pointer text-fg-muted">Install ViStud in your chat app</summary>
            <div class="mt-2 space-y-3">
                <p><span class="font-medium">Claude.</span> Download the package, then in Claude open Customize, Plugins, and upload the zip. Say "let's study week 1" and Claude starts a session from your course.</p>
                <p><span class="font-medium">ChatGPT.</span> Add a custom connector with the address <code class="select-all">{{ $url }}</code>, then sign in here when it asks. The package below holds the same teaching rules; ChatGPT may not load it, and the connector sends the rules either way.</p>
                <p><span class="font-medium">Gemini.</span> Connect it to the same address, under Settings, Connected apps, Custom apps. Sign-in may stop before ViStud ever sees it. If it does, use Prompt for another AI on a study session, and paste the reply back with Save from the chat. The package is untested.</p>
                <ul class="flex flex-wrap gap-3" role="list">
                    <li><a class="link" href="{{ route('connections.plugin', 'claude') }}">Download for Claude</a></li>
                    <li><a class="link" href="{{ route('connections.plugin', 'openai') }}">Download for ChatGPT</a></li>
                    <li><a class="link" href="{{ route('connections.plugin', 'gemini') }}">Download for Gemini</a></li>
                </ul>
                <p class="text-fg-muted">No chat app that connects? Use "Prompt for another AI" on a study session to copy a prompt into any chat. Nothing is saved back that way.</p>
            </div>
        </details>

        @if ($groups !== [])
            <div class="acc-actions">
                <x-button variant="danger" size="sm" wire:click="disconnectAll" wire:confirm="Disconnect every AI app? Each will have to sign in again.">Disconnect all AI apps</x-button>
            </div>
        @endif

        @if ($calls !== [])
            <details class="text-sm text-fg-muted">
                <summary class="cursor-pointer">What they read lately ({{ count($calls) }})</summary>
                <ul class="mt-2 space-y-1" role="list" aria-label="Recent calls">
                    @foreach ($calls as $call)
                        <li>{{ \App\Mcp\Provider::name($call['provider']) }} · {{ str_replace('_', ' ', $call['tool']) }}{{ $call['outcome'] === 'ok' ? '' : ' (refused)' }} · {{ \Illuminate\Support\Carbon::parse($call['at'], 'UTC')->diffForHumans() }}</li>
                    @endforeach
                </ul>
            </details>
        @endif

        @if ($ended !== [])
            <details class="text-sm text-fg-muted">
                <summary class="cursor-pointer">Disconnected ({{ count($ended) }})</summary>
                <ul class="mt-2 space-y-1" role="list">
                    @foreach ($ended as $connection)
                        <li wire:key="ended-{{ $connection->id }}">
                            {{ $connection->providerName() }} · {{ $connection->redirectHost ?? 'unknown address' }}
                            @if ($connection->level !== 'read')
                                <button type="button" class="link" wire:click="chooseUndo('{{ $connection->id }}')">Undo what it did</button>
                            @endif
                        </li>
                        @if ($undoFor === $connection->id)
                            @include('livewire.study.partials.connection-undo')
                        @endif
                    @endforeach
                </ul>
            </details>
        @endif
    </x-accordion>
    @endif
</div>
