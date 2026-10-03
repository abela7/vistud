{{--
    A section of an assignment's plan on a page of its own (App\Http\Controllers\AssignmentPageController::section):
    its tasks, its files and its notes (App\Livewire\Workspaces\SectionPage).
--}}
<x-layouts.app :title="$section->title.' · '.$assignment->title.' · '.$workspace->name" :workspace="$workspace" section="assignments">
    <livewire:workspaces.section-page :workspace-id="$workspace->id" :activity-id="$assignment->id" :section-id="$section->id" />
</x-layouts.app>
