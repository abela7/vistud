{{--
    The assignment companion screen: working on the assignment with the tutor
    (App\Livewire\Workspaces\AssignmentCompanion).
--}}
<x-layouts.app :title="$assignment->title.' · Companion · '.$workspace->name" :workspace="$workspace" section="assignments">
    <livewire:workspaces.assignment-companion :workspace-id="$workspace->id" :activity-id="$assignment->id" />
</x-layouts.app>
