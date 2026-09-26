{{--
    Placeholder landing page after login, until the workspace screens
    arrive (WP6 after the PM's visual review; the full workspace is M2).
--}}
<x-layouts.auth title="Home">
    <main class="surface-subtle flex min-h-dvh flex-col items-center justify-center px-4 py-12">
        <div class="w-full max-w-[26rem] space-y-6 sm:card sm:p-8">
            <x-logo class="h-8" />
            <div class="space-y-1.5">
                <h1 class="text-2xl font-semibold tracking-tight">You're logged in</h1>
                <p class="text-fg-muted">Signed in as {{ auth()->user()->email }}. Your workspace screens are on their way.</p>
            </div>
            <div class="flex items-center justify-between gap-4 rounded-lg border border-border p-4">
                <div>
                    <p class="font-semibold">Two-factor authentication</p>
                    <p class="text-sm text-fg-muted">{{ auth()->user()->two_factor_confirmed_at ? 'On' : 'Off' }}</p>
                </div>
                <a href="{{ route('two-factor.setup') }}" class="btn btn-secondary">{{ auth()->user()->two_factor_confirmed_at ? 'Manage' : 'Set up' }}</a>
            </div>
            <form method="POST" action="{{ route('logout') }}" data-busy-on-submit>
                @csrf
                <x-button type="submit" variant="secondary" busy-label="Logging out…">Log out</x-button>
            </form>
        </div>
    </main>
</x-layouts.auth>
