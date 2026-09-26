{{-- App\Livewire\Journal\EntryShow: one of the student's own entries, read-only. --}}
@php
    use App\View\JournalEntryText as Text;
    $entry = $stored->entry;
@endphp
<article class="space-y-6">
    <header class="space-y-2">
        <h1 class="flex flex-wrap items-center gap-3 text-2xl font-semibold tracking-tight sm:text-3xl">
            {{ Text::title($entry) }}
            <x-journal.outcome :entry="$entry" />
        </h1>
        <p class="text-fg-muted">
            {{ Text::when($entry) }} · recorded by {{ Text::by($entry) }}
            @if (Text::summary($entry) !== '')
                · {{ Text::summary($entry) }}
            @endif
        </p>
    </header>

    @if ($stored->blocked)
        <x-alert tone="info">The words in this entry were removed at your request. What happened is still recorded.</x-alert>
    @endif

    @foreach ($stored->content as $field => $text)
        <section class="space-y-2">
            <h2 class="text-sm font-semibold text-fg-muted">{{ Text::field($field) }}</h2>
            <p class="rounded-lg border border-border bg-surface-sunken p-4 font-mono text-sm break-words whitespace-pre-wrap">{{ $text }}</p>
        </section>
    @endforeach

    <section class="space-y-3">
        <h2 class="text-sm font-semibold text-fg-muted">Details</h2>
        <dl class="account-details rounded-xl border border-border bg-surface-raised">
            @foreach (Text::details($entry) as $label => $value)
                <div>
                    <dt>{{ $label }}</dt>
                    <dd class="break-words">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
</article>
