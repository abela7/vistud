{{--
    Accepting an invitation (WP6; ADR 0003 D4, PM decision Q3). The link is
    /invitation#<token>: resources/js/invitation.js moves the token into the
    form. POST /invitations/accept creates a student account and logs it in.
    Every invalid link gets the same answer, whatever the reason.
--}}
@php
    $invalid = $errors->has('invitation');
    $fieldErrors = collect($errors->get('timezone'))->merge($errors->get('token'));
@endphp
<x-layouts.auth title="Accept your invitation">
    <div class="grid min-h-dvh grid-cols-[minmax(0,1fr)] grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:grid-rows-none">
        <x-auth.brand-panel />

        <main class="surface-subtle flex flex-col items-center px-4 py-8 sm:px-8 sm:py-12 lg:justify-center">
            <div class="w-full max-w-[26rem] space-y-6 sm:card sm:p-8">
                @auth
                    <div class="space-y-1.5">
                        <h1 class="text-2xl font-semibold tracking-tight">You're already logged in</h1>
                        <p class="text-fg-muted">
                            You're logged in as {{ auth()->user()->email }}. To accept an invitation for someone else,
                            log out first, then open the link again, or open it in a private window.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="{{ route('home') }}" class="btn btn-secondary">Go to ViStud</a>
                        <form method="POST" action="{{ route('logout') }}" data-busy-on-submit>
                            @csrf
                            <x-button type="submit" variant="ghost" icon="log-out" busy-label="Logging out…">Log out</x-button>
                        </form>
                    </div>
                @else
                    <div data-invitation-invalid @if (! $invalid) hidden @endif class="space-y-6">
                        <div class="space-y-1.5">
                            <h1 class="text-2xl font-semibold tracking-tight">This link doesn't work</h1>
                            <p class="text-fg-muted">
                                It may have expired, been used already, or been cancelled. Ask the person who invited you for a new link.
                            </p>
                        </div>
                        <p class="border-t border-divider pt-5 text-sm text-fg-muted">
                            Already have an account? <a href="{{ route('login') }}" class="font-medium">Log in</a>
                        </p>
                    </div>

                    @unless ($invalid)
                        <div data-invitation-form-panel class="space-y-6">
                            <div class="space-y-1.5">
                                <h1 class="text-2xl font-semibold tracking-tight">Welcome to ViStud</h1>
                                <p class="text-fg-muted">You've been invited. Choose your name and a password to create your account.</p>
                            </div>

                            <noscript>
                                <x-alert tone="warning">This page needs JavaScript to read your invitation link. Turn it on, then open the link again.</x-alert>
                            </noscript>

                            @if ($fieldErrors->isNotEmpty())
                                <x-alert tone="danger">{{ $fieldErrors->first() }}</x-alert>
                            @endif

                            <form method="POST" action="{{ route('invitations.accept.store') }}" class="space-y-5" novalidate data-busy-on-submit data-invitation-form>
                                @csrf
                                <input type="hidden" name="token" value="">
                                <input type="hidden" name="timezone" value="">

                                <x-field name="name" label="Your name" :value="old('name')" autocomplete="name" enterkeyhint="next" required autofocus />

                                <x-field name="password" label="Password" type="password" revealable hint="At least 12 characters."
                                    autocomplete="new-password" enterkeyhint="next" required />

                                <x-field name="password_confirmation" label="Confirm password" type="password" revealable
                                    autocomplete="new-password" enterkeyhint="go" required />

                                <x-button type="submit" variant="primary" size="lg" class="w-full" busy-label="Creating your account…">Create my account</x-button>
                            </form>

                            <p class="border-t border-divider pt-5 text-sm text-fg-muted">
                                Already have an account? <a href="{{ route('login') }}" class="font-medium">Log in</a>
                            </p>
                        </div>
                    @endunless
                @endauth
            </div>

            <x-appearance-switcher class="mt-8" />
        </main>
    </div>
</x-layouts.auth>
