{{--
    Request a reset link (WP6). Fortify handles POST /forgot-password.
    The status line is the same whether or not the email has an account.
--}}
@php
    $status = session('status');
@endphp
<x-layouts.auth title="Reset your password">
    <div class="grid min-h-dvh grid-cols-[minmax(0,1fr)] grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:grid-rows-none">
        <x-auth.brand-panel />

        <main class="surface-subtle flex flex-col items-center px-4 py-8 sm:px-8 sm:py-12 lg:justify-center">
            <div class="w-full max-w-[26rem] space-y-6 sm:card sm:p-8">
                <div class="space-y-1.5">
                    <h1 class="text-2xl font-semibold tracking-tight">Reset your password</h1>
                    <p class="text-fg-muted">Enter the email address on your ViStud account. We'll send a link if it matches one.</p>
                </div>

                @if ($status)
                    <x-alert tone="success" id="reset-link-status">{{ \App\Http\Responses\NeutralPasswordResetLinkResponse::MESSAGE }}</x-alert>
                @endif

                <form method="POST" action="{{ route('password.email') }}" class="space-y-5" novalidate data-busy-on-submit>
                    @csrf

                    <x-field name="email" label="Email" type="email" :value="old('email')" :error="$status ? false : null"
                        autocomplete="username" inputmode="email" autocapitalize="none" spellcheck="false" required :autofocus="! $status" />

                    <x-button type="submit" variant="primary" size="lg" class="w-full" busy-label="Sending…">Send reset link</x-button>
                </form>

                <p class="border-t border-divider pt-5 text-sm text-fg-muted">
                    <a href="{{ route('login') }}" class="font-medium">Back to log in</a>
                </p>
            </div>

            <x-appearance-switcher class="mt-8" />
        </main>
    </div>
</x-layouts.auth>
