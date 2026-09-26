{{-- One journal entry (WP6). The Livewire component reads it, and answers 404 unless it is the student's own. --}}
<x-layouts.app title="Journal entry">
    <div class="mx-auto max-w-3xl space-y-6">
        <a href="{{ route('journal.index') }}" class="inline-flex items-center gap-1.5 text-sm font-medium">
            <x-icon name="arrow-left" class="size-4" />Journal
        </a>
        <livewire:journal.entry-show :entry-id="$entry" />
    </div>
</x-layouts.app>
