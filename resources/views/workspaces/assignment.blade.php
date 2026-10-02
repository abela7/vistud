{{--
    An assignment on a page of its own, or a new one (App\Http\Controllers\AssignmentPageController): its
    name, kind, deadline and module, where the student is with it, and its files (App\Livewire\Workspaces\AssignmentPage).
--}}
<x-layouts.app :title="($assignment?->title ?? 'New assignment').' · Assignments · '.$workspace->name" :workspace="$workspace" section="assignments">
    <livewire:workspaces.assignment-page :workspace-id="$workspace->id" :activity-id="$assignment?->id" :in-module="$module" />
</x-layouts.app>
