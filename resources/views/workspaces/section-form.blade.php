{{--
    A new section of an assignment's plan, or changing one, on a page of its own
    (App\Http\Controllers\AssignmentPageController; App\Livewire\Workspaces\SectionForm).
--}}
<x-layouts.app :title="($section?->title ?? 'New section').' · '.$assignment->title.' · '.$workspace->name" :workspace="$workspace" section="assignments">
    <livewire:workspaces.section-form :workspace-id="$workspace->id" :activity-id="$assignment->id" :section-id="$section?->id" />
</x-layouts.app>
