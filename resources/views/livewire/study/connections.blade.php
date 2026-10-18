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
                            <x-button variant="ghost" size="sm" wire:click="disconnect('{{ $connection->id }}')" wire:confirm="Disconnect this app? It will have to sign in again.">Disconnect</x-button>
                        </li>
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

        @if ($groups !== [])
            <div class="acc-actions">
                <x-button variant="danger" size="sm" wire:click="disconnectAll" wire:confirm="Disconnect every AI app? Each will have to sign in again.">Disconnect all AI apps</x-button>
            </div>
        @endif

        @if ($ended !== [])
            <details class="text-sm text-fg-muted">
                <summary class="cursor-pointer">Disconnected ({{ count($ended) }})</summary>
                <ul class="mt-2 space-y-1" role="list">
                    @foreach ($ended as $connection)
                        <li wire:key="ended-{{ $connection->id }}">{{ $connection->providerName() }} · {{ $connection->redirectHost ?? 'unknown address' }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
    </x-accordion>
    @endif
</div>
