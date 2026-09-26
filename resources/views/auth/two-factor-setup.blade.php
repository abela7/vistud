{{--
    Two-factor setup (WP6; ADR 0003 §10.3, DESIGN.md §7.1). Four states:
    off, confirming (scan and enter a code), just confirmed or regenerated
    (recovery codes, shown once), and on. Fortify handles every POST.
--}}
@php
    $confirmError = $errors->confirmTwoFactorAuthentication->first('code') ?: null;
    $title = match (true) {
        $recoveryCodes !== null => 'Save your recovery codes',
        $state === 'confirming' => 'Set up your authenticator app',
        $state === 'on' => 'Two-factor authentication is on',
        default => 'Two-factor authentication',
    };
@endphp
<x-layouts.app :title="$title" :area="$area">
    <div class="mx-auto max-w-xl">
        <div class="space-y-6 sm:card sm:p-8">
                <h1 class="text-2xl font-semibold tracking-tight">{{ $title }}</h1>

                @if ($recoveryCodes !== null)
                    @if (session('status') === 'two-factor-authentication-confirmed')
                        <x-alert tone="success">Your authenticator app is linked. From now on, logging in asks for a code from it.</x-alert>
                    @endif

                    <p class="text-fg-muted">
                        Each code lets you log in once if you lose your phone. Keep them somewhere safe, such as a password manager.
                        <strong class="font-semibold text-fg">They won't be shown again.</strong>
                    </p>

                    <ul id="recovery-codes" class="grid grid-cols-1 gap-y-1.5 rounded-lg border border-border bg-surface-sunken p-4 font-mono text-sm" aria-label="Recovery codes">
                        @foreach ($recoveryCodes as $code)
                            <li>{{ $code }}</li>
                        @endforeach
                    </ul>

                    <div class="flex flex-wrap gap-3">
                        <x-button variant="secondary" data-copy-target="recovery-codes" data-copied-label="Copied">Copy codes</x-button>
                    </div>

                    <a href="{{ route('home') }}" class="btn btn-primary btn-lg w-full">I've saved them</a>
                @elseif ($state === 'confirming')
                    <ol class="space-y-6">
                        <li class="space-y-3">
                            <p><span class="font-semibold">1. Scan this QR code</span> with an authenticator app, such as Google Authenticator, Microsoft Authenticator or 1Password.</p>
                            <div class="flex justify-center">
                                <div class="qr-plate">{!! $qrCode !!}</div>
                            </div>
                            <div class="space-y-1.5 text-sm">
                                <p class="text-fg-muted">Can't scan it? Enter this key in the app instead:</p>
                                <div class="flex flex-wrap items-center gap-3">
                                    <code id="setup-key" class="font-mono text-base tracking-wider">{{ trim(chunk_split($secret, 4, ' ')) }}</code>
                                    <x-button variant="ghost" data-copy-target="setup-key" data-copied-label="Copied">Copy key</x-button>
                                </div>
                            </div>
                        </li>

                        <li class="space-y-3">
                            <p><span class="font-semibold">2. Enter the 6-digit code</span> the app shows.</p>
                            <form method="POST" action="{{ route('two-factor.confirm') }}" class="space-y-5" novalidate data-busy-on-submit>
                                @csrf
                                <x-field name="code" label="Authentication code" type="text" inputmode="numeric" autocomplete="one-time-code"
                                    maxlength="6" pattern="[0-9]*" enterkeyhint="go" autocapitalize="none" spellcheck="false"
                                    :error="$confirmError" autofocus />
                                <x-button type="submit" variant="primary" size="lg" class="w-full" busy-label="Confirming…">Confirm</x-button>
                            </form>
                        </li>
                    </ol>

                    <form method="POST" action="{{ route('two-factor.disable') }}" class="border-t border-divider pt-5" data-busy-on-submit>
                        @csrf
                        @method('DELETE')
                        <x-button type="submit" variant="ghost" busy-label="Cancelling…">Cancel setup</x-button>
                    </form>
                @elseif ($state === 'on')
                    @if (session('status') === 'two-factor-authentication-confirmed')
                        <x-alert tone="success">Your authenticator app is linked.</x-alert>
                    @endif

                    <p class="text-fg-muted">Each time you log in, you'll be asked for a code from your authenticator app.</p>

                    <div class="space-y-3 rounded-lg border border-border p-4">
                        <p class="font-semibold">Lost your recovery codes?</p>
                        <p class="text-sm text-fg-muted">Generating new ones replaces the old ones, which stop working.</p>
                        <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}" data-busy-on-submit>
                            @csrf
                            <x-button type="submit" variant="secondary" busy-label="Generating…">Generate new recovery codes</x-button>
                        </form>
                    </div>
                @else
                    <p class="text-fg-muted">
                        Add a second step to logging in: after your password, you enter a 6-digit code from an authenticator app on your phone.
                        Admins must turn this on before they can use the admin area.
                    </p>

                    <form method="POST" action="{{ route('two-factor.enable') }}" data-busy-on-submit>
                        @csrf
                        <x-button type="submit" variant="primary" size="lg" class="w-full" busy-label="Turning on…">Turn on two-factor authentication</x-button>
                    </form>
                @endif
            </div>
    </div>
</x-layouts.app>
