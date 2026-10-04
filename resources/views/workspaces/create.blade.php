{{--
    The New course page (App\Livewire\Workspaces\CourseNew): a page of its own, not a dialog, so a course starts with room to
    look right and a choice of how to set it up (the AI's guide, or by hand).
--}}
<x-layouts.app title="New course">
    <div class="mx-auto max-w-5xl space-y-6">
        <x-page title="New course" :back-href="route('home')" back-to="All courses" context="Name it, then choose how to set it up." />
        <livewire:workspaces.course-new />
    </div>
</x-layouts.app>
