{{--
    Password confirmation (WP6; DESIGN.md §7.1). Fortify handles
    POST /user/confirm-password. A wrong password is a field error, not
    a form-level alert.
--}}
<x-layouts.auth title="Confirm your password">
    <div class="grid min-h-dvh grid-cols-[minmax(0,1fr)] grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:grid-rows-none">
        <x-auth.brand-panel />

        <main class="surface-subtle flex flex-col items-center px-4 py-8 sm:px-8 sm:py-12 lg:justify-center">
            <div class="w-full max-w-[26rem] space-y-6 sm:card sm:p-8">
                <div class="space-y-1.5">
                    <h1 class="text-2xl font-semibold tracking-tight">Confirm your password</h1>
                    <p class="text-fg-muted">For your security, enter your password to continue.</p>
                </div>

                <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-5" novalidate data-busy-on-submit>
                    @csrf

                    <x-field name="password" label="Password" type="password" revealable
                        autocomplete="current-password" enterkeyhint="go" required autofocus />

                    <x-button type="submit" variant="primary" size="lg" class="w-full" busy-label="Confirming…">Confirm</x-button>
                </form>

                <p class="border-t border-divider pt-5 text-sm text-fg-muted">
                    <a href="{{ route('home') }}" class="font-medium">Cancel</a>
                </p>
            </div>

            <x-appearance-switcher class="mt-8" />
        </main>
    </div>
</x-layouts.auth>
