{{--
    The page where the student says Yes to a change a chat app asked for (App\Http\Controllers\ConfirmChangeController,
    docs/specs/mcp-connections-plan.md M6.5). Opening it changes nothing; only the button below does.
--}}
<x-layouts.app title="Confirm a change">
    <div class="mx-auto max-w-xl space-y-6">
        <x-page title="Confirm a change" :back-href="route('home')" back-to="All courses" context="A chat app asked for this. Nothing changes until you say yes." />

        @if ($done)
            <p class="settings-note" role="status"><x-icon name="info" class="size-4" />{{ $done }}</p>
        @else
            @if ($problem)
                <x-alert tone="danger">{{ $problem }}</x-alert>
            @endif
            <div class="card space-y-3 p-5" data-test="change">
                <p class="font-medium">{{ $change['verb'] }}</p>
                @if ($change['target'])
                    <p class="break-words"><span class="text-fg-muted">Now:</span> {{ $change['target'] }}</p>
                @elseif ($change['before'])
                    <p class="break-words"><span class="text-fg-muted">Replaces:</span> {{ $change['before'] }}</p>
                @endif
                @if ($change['after'])
                    <p class="break-words"><span class="text-fg-muted">{{ $change['tool'] === 'replace_note' ? 'New text:' : 'To:' }}</span> {{ \Illuminate\Support\Str::limit($change['after'], 600) }}</p>
                @endif
                <p class="text-sm text-fg-muted">Asked for by {{ $change['provider'] }}'s chat app. @if ($change['password']) You will be asked for your password. @endif</p>
            </div>

            <form method="POST" action="{{ route('connections.confirm.store', $change['id']) }}" class="flex flex-wrap gap-3" data-busy-on-submit>
                @csrf
                <x-button type="submit" variant="primary" busy-label="Making the change…">Yes, make this change</x-button>
                <a href="{{ route('home') }}" class="btn btn-ghost">No, leave it</a>
            </form>
        @endif
    </div>
</x-layouts.app>
