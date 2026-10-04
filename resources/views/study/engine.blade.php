{{-- A student's AI settings (docs/specs/study-memory.md §6): the page's top, then one Livewire component. --}}
<x-layouts.app title="AI settings">
    <div class="mx-auto max-w-3xl space-y-6">
        <x-page title="AI settings" :back-href="route('home')" back-to="All courses" context="Your key, your models and what they cost." />
        <livewire:study.engine-settings />
    </div>
</x-layouts.app>
