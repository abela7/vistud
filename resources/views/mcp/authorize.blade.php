{{--
    The consent page an AI app's sign-in lands on (docs/specs/mcp-connections-plan.md §3.2, M6.1). Shown every time,
    never framed by another site (App\Mcp\Http\AuthorizeConnection). Approve posts to App\Mcp\Http\ApproveConnection,
    which records the connection before Passport sends the student back; Deny is Passport's own.
--}}
@php
    $redirect = is_array($client->redirect_uris) ? ($client->redirect_uris[0] ?? '') : '';
    $provider = \App\Mcp\Provider::fromRedirect($redirect);
    $host = parse_url($redirect, PHP_URL_HOST) ?: 'an unknown address';
    $local = $provider === \App\Mcp\Provider::LOCAL;
@endphp
<x-layouts.auth title="Connect an AI app">
    <div class="grid min-h-dvh grid-cols-[minmax(0,1fr)] grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:grid-rows-none">
        <x-auth.brand-panel />

        <main class="surface-subtle flex flex-col items-center px-4 py-8 sm:px-8 sm:py-12 lg:justify-center">
            <div class="w-full max-w-[30rem] space-y-6 sm:card sm:p-8">
                <div class="space-y-1.5">
                    <h1 class="text-2xl font-semibold tracking-tight">Connect {{ \App\Mcp\Provider::name($provider) }}?</h1>
                    <p class="text-fg-muted">
                        @if ($local)
                            A program on this computer (for example Claude Code) wants to use your ViStud account.
                        @else
                            An app at <strong class="font-medium text-fg">{{ $host }}</strong> wants to use your ViStud account.
                        @endif
                    </p>
                </div>

                <p class="rounded-lg border border-border bg-surface p-3 text-sm">
                    <strong class="font-medium">Only approve if you just clicked Connect</strong> in your own Claude, ChatGPT or Gemini. If you didn't, choose Don't connect.
                </p>

                <div class="space-y-2 text-sm">
                    <p class="font-medium">What leaves ViStud</p>
                    <ul class="list-disc space-y-1 pl-5 text-fg-muted">
                        <li>Your notes, file text, questions and quiz answers go to {{ $local ? 'that program' : \App\Mcp\Provider::name($provider) }} when the chat asks for them.</li>
                        <li>Its own memory and training settings apply there. ViStud can't switch them off.</li>
                        <li>ViStud stays the record: what the chat saves lands here, and you can cut it off in Settings at any time.</li>
                    </ul>
                </div>

                <form method="POST" action="{{ route('mcp.approve') }}" class="space-y-5" data-busy-on-submit>
                    @csrf
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-medium">What it may do</legend>
                        @foreach (\App\Mcp\Connections::LEVELS as $value => $label)
                            <label class="flex min-h-[44px] items-center gap-3 text-sm">
                                <input type="radio" name="level" value="{{ $value }}" @checked($value === 'write')>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </fieldset>

                    <x-button type="submit" variant="primary" size="lg" class="w-full" busy-label="Connecting…">Connect</x-button>
                </form>

                <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="state" value="{{ $request->state }}">
                    <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                    <input type="hidden" name="auth_token" value="{{ $authToken }}">
                    <x-button type="submit" variant="ghost" size="lg" class="w-full">Don't connect</x-button>
                </form>

                <p class="border-t border-divider pt-5 text-xs text-fg-muted">
                    Signed in as {{ \App\Mcp\Tools\MeTool::masked((string) $user->email) }}. App registered {{ optional($client->created_at)->diffForHumans() ?? 'just now' }}.
                </p>
            </div>
        </main>
    </div>
</x-layouts.auth>
