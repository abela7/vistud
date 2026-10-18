{{--
    Settings, one page with four parts (docs/specs/vistud-2-blueprint.md §3.4): the AI (the student's key, models and
    what they cost: App\Livewire\Study\EngineSettings), Appearance, Security and Your data (the journal). The part is
    `?part=`; the strip above is the way between them. Every part is built like the AI part: folded panels
    (x-accordion) with an icon, a title and one line of where it stands, two columns on a wide screen, all at one width.
    The parts other than the AI are short, so their panels start open.
--}}
@php
    $parts = [
        'ai' => ['AI', 'AI settings', 'Your key, your models and what they cost.', 'brain'],
        'appearance' => ['Appearance', 'Appearance', 'Your theme, and whether it is light or dark.', 'palette'],
        'security' => ['Security', 'Security', 'How you sign in.', 'shield-check'],
        'data' => ['Your data', 'Your data', 'What ViStud has recorded about your studying.', 'notebook-text'],
    ];
    [$label, $title, $context] = $parts[$part];
    $user = auth()->user();
@endphp
<x-layouts.app :title="$title">
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page title="Settings" :back-href="route('home')" back-to="All courses" :context="$context" />

        <nav aria-label="Settings" class="page-tabs">
            @foreach ($parts as $key => [$name, , , $icon])
                <a href="{{ route('settings', ['part' => $key]) }}" class="page-tab" @if ($key === $part) aria-current="page" @endif><x-icon :name="$icon" class="size-4" />{{ $name }}</a>
            @endforeach
        </nav>

        @if ($part === 'ai')
            <livewire:study.engine-settings />
            <div class="acc-cols"><livewire:study.connections /></div>
        @elseif ($part === 'appearance')
            <div class="acc-cols">
                <x-accordion id="theme" title="Theme" icon="palette" :open="true">
                    <x-slot:status>The colours of ViStud. Each theme has a light and a dark look.</x-slot:status>
                    <x-theme-picker class="settings-bare-fieldset" />
                </x-accordion>

                <x-accordion id="mode" title="Light or dark" icon="moon" :open="true">
                    <x-slot:status>System follows your device's own setting.</x-slot:status>
                    <x-appearance-switcher class="settings-mode-switch" />
                    <p class="settings-note"><x-icon name="info" class="size-4" />Both are kept in this browser, so another device can look different.</p>
                </x-accordion>
            </div>
        @elseif ($part === 'security')
            <div class="acc-cols">
                <x-accordion id="two-step" title="Two-step sign-in" icon="shield-check" :open="true">
                    <x-slot:status>
                        @if ($twoFactor)
                            <span class="badge badge-success"><x-icon name="circle-check" class="size-3.5" />On</span> A code from your app at every sign-in
                        @else
                            <span class="badge badge-warning"><x-icon name="triangle-alert" class="size-3.5" />Off</span> Your password alone signs you in
                        @endif
                    </x-slot:status>

                    <p class="acc-note">{{ $twoFactor ? 'Signing in asks for a code from your authenticator app, so a stolen password alone is not enough.' : 'Turn it on to be asked for a code from an authenticator app when you sign in.' }}</p>
                    @unless ($twoFactor)
                        <ol class="settings-steps">
                            <li>Install an authenticator app on your phone, like Google Authenticator or Microsoft Authenticator.</li>
                            <li>Press <strong>Turn on</strong> and scan the code it shows with the app.</li>
                            <li>From then on, signing in asks for the six-digit code the app shows.</li>
                        </ol>
                    @endunless
                    <div class="acc-actions">
                        <a href="{{ route('two-factor.setup') }}" @class(['btn btn-sm', 'btn-primary' => ! $twoFactor, 'btn-secondary' => $twoFactor])><x-icon name="shield-check" class="size-4" />{{ $twoFactor ? 'Manage' : 'Turn on' }}</a>
                    </div>
                </x-accordion>

                <x-accordion id="account" title="Signed in as" icon="user-round" :open="true">
                    <x-slot:status>{{ $user?->email }}</x-slot:status>
                    <dl class="settings-facts">
                        <div><dt>Name</dt><dd>{{ $user?->name }}</dd></div>
                        <div><dt>Email</dt><dd>{{ $user?->email }}</dd></div>
                        @if ($user?->created_at)
                            <div><dt>Member since</dt><dd>{{ $user->created_at->format('j F Y') }}</dd></div>
                        @endif
                    </dl>
                </x-accordion>
            </div>
        @else
            <div class="acc-cols">
                <x-accordion id="record" title="Your study record" icon="notebook-text" :open="true">
                    <x-slot:status><span class="badge"><x-icon name="eye-off" class="size-3.5" />Only you</span> Newest first</x-slot:status>
                    <p class="acc-note">Everything ViStud has recorded about your studying, newest first. Only you can see it.</p>
                    <ul class="settings-chips" role="list" aria-label="What it holds">
                        @foreach (['Sessions', 'Topics and how they stand', 'Questions', 'Quizzes', 'Flashcard reviews'] as $holds)
                            <li class="badge">{{ $holds }}</li>
                        @endforeach
                    </ul>
                    <div class="acc-actions">
                        <a href="{{ route('journal.index') }}" class="btn btn-secondary btn-sm"><x-icon name="notebook-text" class="size-4" />Open your study record</a>
                    </div>
                </x-accordion>

                <x-accordion id="notes" title="Your notes as files" icon="download" :open="true">
                    <x-slot:status>PDF, Word, Markdown or text</x-slot:status>
                    <p class="acc-note">Each note can be kept as a file from its own page.</p>
                    <ul class="settings-chips" role="list" aria-label="File kinds">
                        @foreach (['PDF', 'Word (.docx)', 'Markdown', 'Plain text'] as $kind)
                            <li class="badge"><x-icon name="file-text" class="size-3.5" />{{ $kind }}</li>
                        @endforeach
                    </ul>
                    <p class="settings-note"><x-icon name="info" class="size-4" />Open a note, then use <strong>Save</strong> or <strong>Share</strong> at its top.</p>
                </x-accordion>
            </div>
        @endif
    </div>
</x-layouts.app>
