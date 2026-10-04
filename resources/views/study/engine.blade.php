{{-- A student's AI engine page (docs/specs/study-memory.md §6): one Livewire component. --}}
<x-layouts.app title="AI engine">
    <div class="mx-auto max-w-3xl space-y-6">
        <x-back :href="route('home')" to="All workspaces" />
        <livewire:study.engine-settings />
    </div>
</x-layouts.app>
