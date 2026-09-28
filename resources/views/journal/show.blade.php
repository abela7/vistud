{{-- One journal entry (WP6). The Livewire component reads it, and answers 404 unless it is the student's own. --}}
<x-layouts.app title="Journal entry">
    <div class="mx-auto max-w-3xl space-y-6">
        <x-back :href="route('journal.index')" to="Journal" />
        <livewire:journal.entry-show :entry-id="$entry" />
    </div>
</x-layouts.app>
