{{-- The admin area's AI engine page (docs/specs/study-memory.md §6): one Livewire component. --}}
<x-layouts.app area="admin" title="AI engine">
    <div class="mx-auto max-w-5xl space-y-8">
        <x-back :href="route('admin.overview')" to="Admin overview" />
        <livewire:admin.engine-setup />
    </div>
</x-layouts.app>
