{{-- The admin area's Skills page: one Livewire component. --}}
<x-layouts.app area="admin" title="Skills">
    <div class="mx-auto max-w-5xl space-y-8">
        <x-back :href="route('admin.overview')" to="Admin overview" />
        <livewire:admin.skills-admin />
    </div>
</x-layouts.app>
