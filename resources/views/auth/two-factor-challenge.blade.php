{{--
    Two-factor challenge (WP6; DESIGN.md §7.1). Fortify handles
    POST /two-factor-challenge. A wrong code is one message for the form,
    the same way a refused login is (conventions.md).
--}}
@php
    $usingRecovery = $errors->has('recovery_code');
    $challengeError = $errors->first('recovery_code') ?: $errors->first('code');
    $swapLabel = $usingRecovery ? 'Use an authentication code instead' : 'Use a recovery code instead';
@endphp
<x-layouts.auth title="Two-step verification">
    <div class="grid min-h-dvh grid-cols-[minmax(0,1fr)] grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:grid-rows-none">
        <x-auth.brand-panel />

        <main class="surface-subtle flex flex-col items-center px-4 py-8 sm:px-8 sm:py-12 lg:justify-center">
            <div class="w-full max-w-[26rem] space-y-6 sm:card sm:p-8">
                <div class="space-y-1.5">
                    <h1 class="text-2xl font-semibold tracking-tight">Two-step verification</h1>
                    <p class="text-fg-muted">Enter the 6-digit code from your authenticator app, or use a recovery code.</p>
                </div>

                @if ($challengeError)
                    <x-alert tone="danger" title="We couldn't verify that code" id="two-factor-error" tabindex="-1" autofocus>
                        {{ $challengeError }}
                    </x-alert>
                @endif

                <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-5" novalidate data-busy-on-submit data-challenge-mode="{{ $usingRecovery ? 'recovery' : 'code' }}">
                    @csrf

                    <div data-challenge-panel="code" @if ($usingRecovery) hidden @endif>
                        <x-field name="code" label="Authentication code" type="text" inputmode="numeric" autocomplete="one-time-code"
                            maxlength="6" enterkeyhint="go" autocapitalize="none" spellcheck="false" pattern="[0-9]*"
                            :error="false" :disabled="$usingRecovery" :autofocus="! $challengeError && ! $usingRecovery" />
                    </div>

                    <div data-challenge-panel="recovery" @unless ($usingRecovery) hidden @endunless>
                        <x-field name="recovery_code" label="Recovery code" type="text" autocomplete="off"
                            autocapitalize="none" spellcheck="false" enterkeyhint="go"
                            :error="false" :disabled="! $usingRecovery" :autofocus="! $challengeError && $usingRecovery" />
                    </div>

                    <button type="button" class="text-sm font-medium text-accent-contrast underline underline-offset-2 hover:text-accent-hover hover:decoration-2" data-challenge-swap>{{ $swapLabel }}</button>

                    <x-button type="submit" variant="primary" size="lg" class="w-full" busy-label="Verifying…">Verify</x-button>
                </form>

                <p class="border-t border-divider pt-5 text-sm text-fg-muted">
                    <a href="{{ route('login') }}" class="font-medium">Back to log in</a>
                </p>
            </div>

            <x-appearance-switcher class="mt-8" />
        </main>
    </div>
</x-layouts.auth>
