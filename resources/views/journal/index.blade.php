{{--
    The student's journal, newest first (WP6). Entries are never changed:
    corrections arrive as new entries (ADR 0002).
--}}
@php
    use App\View\JournalEntryText as Text;
@endphp
<x-layouts.app title="Journal">
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="space-y-2">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Journal</h1>
            <p class="max-w-2xl text-fg-muted">
                Everything ViStud has recorded about your studying, newest first. Only you can see it.
            </p>
        </div>

        @if ($entries === [])
            <p class="rounded-xl border border-dashed border-border-strong px-5 py-8 text-center text-fg-muted">
                Nothing recorded yet. Entries appear here as you study with ViStud.
            </p>
        @else
            <ol class="account-list divide-y divide-divider" role="list">
                @foreach ($entries as $entry)
                    <li>
                        <a href="{{ route('journal.show', $entry->id) }}" class="flex items-start gap-3 px-4 py-3 no-underline hover:bg-hover sm:px-5">
                            <div class="min-w-0 flex-1 space-y-0.5">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold text-fg">{{ Text::title($entry) }}</span>
                                    <x-journal.outcome :entry="$entry" />
                                </p>
                                @if (Text::summary($entry) !== '')
                                    <p class="text-sm break-words text-fg-muted">{{ Text::summary($entry) }}</p>
                                @endif
                            </div>
                            <p class="shrink-0 text-sm text-fg-muted">{{ Text::when($entry) }}</p>
                        </a>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</x-layouts.app>
