{{--
    Login (WP6; DESIGN.md §7.1). Fortify handles POST /login. A refused
    login shows one message whatever the reason (conventions.md), so it is
    shown for the form, not against a field.
--}}
@php
    $credentialsError = collect($errors->get('email'))->first(fn ($message) => $message === __('auth.failed')
        || str_starts_with($message, strstr(__('auth.throttle'), ':seconds', true)));
@endphp
<x-layouts.auth title="Log in">
    <div class="grid min-h-dvh grid-cols-[minmax(0,1fr)] grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:grid-rows-none">
        <x-auth.brand-panel />

        <main class="surface-subtle flex flex-col items-center px-4 py-8 sm:px-8 sm:py-12 lg:justify-center">
            <div class="w-full max-w-[26rem] space-y-6 sm:card sm:p-8">
                <div class="space-y-1.5">
                    <h1 class="text-2xl font-semibold tracking-tight">Log in</h1>
                    <p class="text-fg-muted">Welcome back. Use the email and password for your ViStud account.</p>
                </div>

                @if ($credentialsError)
                    <x-alert tone="danger" title="We couldn't log you in" id="login-error" tabindex="-1" autofocus>
                        {{ $credentialsError }}
                    </x-alert>
                @endif

                @if (session('status'))
                    <x-alert tone="success">{{ session('status') }}</x-alert>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="space-y-5" novalidate data-busy-on-submit>
                    @csrf

                    <x-field name="email" label="Email" type="email" :value="old('email')" :error="$credentialsError ? false : null"
                        autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" required :autofocus="! $credentialsError" />

                    <x-field name="password" label="Password" type="password" revealable
                        autocomplete="current-password" enterkeyhint="go" required>
                        @if (Route::has('password.request'))
                            <x-slot:aside>
                                <a href="{{ route('password.request') }}" class="text-sm font-medium">Forgot password?</a>
                            </x-slot:aside>
                        @endif
                    </x-field>

                    <x-checkbox name="remember" label="Keep me logged in on this device" :checked="(bool) old('remember')" />

                    <x-button type="submit" variant="primary" size="lg" class="w-full" busy-label="Logging in…">Log in</x-button>
                </form>

                <p class="border-t border-divider pt-5 text-sm text-fg-muted">
                    ViStud is invitation-only. To get an account, ask the person who runs your ViStud to send you an invitation.
                </p>
            </div>

            <x-appearance-switcher class="mt-8" />
        </main>
    </div>
</x-layouts.auth>
