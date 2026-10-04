{{--
    Settings, one page with four parts (docs/specs/vistud-2-blueprint.md §3.4): the AI engine (the student's key, models and
    what they cost: App\Livewire\Study\EngineSettings), Appearance, Security and Your data (the journal). The part is
    `?part=`; each part is one panel, and the strip above is the way between them.
--}}
@php
    $parts = [
        'ai' => ['AI engine', 'AI settings', 'Your key, your models and what they cost.', 'brain'],
        'appearance' => ['Appearance', 'Appearance', 'Light, dark, or the way your device is set.', 'palette'],
        'security' => ['Security', 'Security', 'How you sign in.', 'shield-check'],
        'data' => ['Your data', 'Your data', 'What ViStud has recorded about your studying.', 'notebook-text'],
    ];
    [$label, $title, $context] = $parts[$part];
@endphp
<x-layouts.app :title="$title">
    <div class="mx-auto max-w-3xl space-y-6">
        <x-page title="Settings" :back-href="route('home')" back-to="All courses" :context="$context" />

        <nav aria-label="Settings" class="page-tabs">
            @foreach ($parts as $key => [$name])
                <a href="{{ route('settings', ['part' => $key]) }}" class="page-tab" @if ($key === $part) aria-current="page" @endif>{{ $name }}</a>
            @endforeach
        </nav>

        @if ($part === 'ai')
            <livewire:study.engine-settings />
        @elseif ($part === 'appearance')
            <section class="settings-panel" aria-labelledby="settings-appearance">
                <h2 id="settings-appearance" class="panel-title">Appearance</h2>
                <x-appearance-switcher />
            </section>
        @elseif ($part === 'security')
            <section class="settings-panel" aria-labelledby="settings-security">
                <h2 id="settings-security" class="panel-title">Two-step sign-in</h2>
                <p class="text-fg-muted">{{ $twoFactor ? 'On: signing in asks for a code from your authenticator app.' : 'Off. Turn it on to be asked for a code from an authenticator app when you sign in.' }}</p>
                <a href="{{ route('two-factor.setup') }}" class="btn btn-secondary"><x-icon name="shield-check" class="size-4" />{{ $twoFactor ? 'Manage' : 'Turn on' }}</a>
            </section>
        @else
            <section class="settings-panel" aria-labelledby="settings-data">
                <h2 id="settings-data" class="panel-title">Your journal</h2>
                <p class="text-fg-muted">Everything ViStud has recorded about your studying, newest first. Only you can see it.</p>
                <a href="{{ route('journal.index') }}" class="btn btn-secondary"><x-icon name="notebook-text" class="size-4" />Open the journal</a>
            </section>
            <section class="settings-panel" aria-labelledby="settings-export">
                <h2 id="settings-export" class="panel-title">Your notes</h2>
                <p class="text-fg-muted">Each note can be kept as a PDF, a Word file, Markdown or text from its own page.</p>
            </section>
        @endif
    </div>
</x-layouts.app>
