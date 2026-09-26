{{--
    Home. A student's is My workspaces (docs/specs/workspaces.md); an account
    that is only an admin has no workspaces, so its home shows the account's
    security and the way into the admin area.
--}}
@php
    $user = auth()->user();
    $firstName = strtok($user->name, ' ') ?: $user->name;
    $twoFactorOn = $user->two_factor_confirmed_at !== null;
    $isAdmin = app(\App\Identity\PrincipalFactory::class)->fromRequest(request())->hasRole(\App\Platform\Access\Role::Admin);
    $isNew = session('status') === 'invitation-accepted';
    $isStudent = app(\App\Identity\PrincipalFactory::class)->fromRequest(request())->hasRole(\App\Platform\Access\Role::Student);
@endphp
@if ($isStudent)
<x-layouts.app title="My workspaces">
    <div class="mx-auto max-w-5xl space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="space-y-1">
                <p class="text-fg-muted">{{ $isNew ? 'Welcome' : 'Welcome back' }}, {{ $firstName }}</p>
                <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">My workspaces</h1>
            </div>
            <x-button variant="primary" icon="plus" x-data x-on:click="$dispatch('workspace-form-open')">New workspace</x-button>
        </div>

        @if ($isNew)
            <x-alert tone="success" title="Your account is ready">
                From now on, log in with {{ $user->email }} and the password you just chose.
            </x-alert>
        @endif
        @if (session('workspace-notice'))
            <x-alert tone="success">{{ session('workspace-notice') }}</x-alert>
        @endif

        <livewire:workspaces.index />
    </div>

    <livewire:workspaces.form :open-on-load="request()->boolean('new')" />
</x-layouts.app>
@else
<x-layouts.app title="Home">
    <div class="mx-auto max-w-4xl space-y-8">
        <div class="space-y-1.5">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ $isNew ? 'Welcome' : 'Welcome back' }}, {{ $firstName }}</h1>
            <p class="text-fg-muted">Signed in as {{ $user->email }}. Your notes, courses and calendar arrive with the study workspace.</p>
        </div>

        @if ($isNew)
            <x-alert tone="success" title="Your account is ready">
                From now on, log in with {{ $user->email }} and the password you just chose.
            </x-alert>
        @endif

        <div class="grid gap-4 md:grid-cols-2">
            <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface-raised p-5">
                <div class="flex items-start gap-3">
                    <span class="rounded-lg bg-accent-subtle p-2 text-accent-contrast"><x-icon name="shield-check" /></span>
                    <div class="space-y-1">
                        <h2 class="font-semibold">Two-factor authentication</h2>
                        <p class="text-sm text-fg-muted">{{ $twoFactorOn ? 'On. Logging in asks for a code from your authenticator app.' : 'Off. Add a code from your phone to every login.' }}</p>
                    </div>
                </div>
                <a href="{{ route('two-factor.setup') }}" class="btn btn-secondary self-start">{{ $twoFactorOn ? 'Manage' : 'Set up' }}</a>
            </section>

            @if ($isAdmin)
                <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface-raised p-5">
                    <div class="flex items-start gap-3">
                        <span class="rounded-lg bg-accent-subtle p-2 text-accent-contrast"><x-icon name="shield" /></span>
                        <div class="space-y-1">
                            <x-admin.marker />
                            <p class="text-sm text-fg-muted">Manage accounts and see the audit log.</p>
                        </div>
                    </div>
                    <a href="{{ route('admin.overview') }}" class="btn btn-secondary self-start">Admin area</a>
                </section>
            @endif
        </div>
    </div>
</x-layouts.app>
@endif
