{{--
    Choose a new password (WP6). Fortify handles POST /reset-password.
    A bad token or a password that breaks the rules is a field error.
--}}
<x-layouts.auth title="Choose a new password">
    <div class="grid min-h-dvh grid-cols-[minmax(0,1fr)] grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:grid-rows-none">
        <x-auth.brand-panel />

        <main class="surface-subtle flex flex-col items-center px-4 py-8 sm:px-8 sm:py-12 lg:justify-center">
            <div class="w-full max-w-[26rem] space-y-6 sm:card sm:p-8">
                <div class="space-y-1.5">
                    <h1 class="text-2xl font-semibold tracking-tight">Choose a new password</h1>
                    <p class="text-fg-muted">Enter the email the link was sent to, then choose a new password.</p>
                </div>

                <form method="POST" action="{{ route('password.update') }}" class="space-y-5" novalidate data-busy-on-submit>
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">

                    <x-field name="email" label="Email" type="email" :value="old('email', $email)"
                        autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" required autofocus />

                    <x-field name="password" label="New password" type="password" revealable
                        autocomplete="new-password" enterkeyhint="next" required />

                    <x-field name="password_confirmation" label="Confirm new password" type="password" revealable
                        autocomplete="new-password" enterkeyhint="go" required />

                    <x-button type="submit" variant="primary" size="lg" class="w-full" busy-label="Resetting…">Reset password</x-button>
                </form>

                <p class="border-t border-divider pt-5 text-sm text-fg-muted">
                    <a href="{{ route('login') }}" class="font-medium">Back to log in</a>
                </p>
            </div>

            <x-appearance-switcher class="mt-8" />
        </main>
    </div>
</x-layouts.auth>
