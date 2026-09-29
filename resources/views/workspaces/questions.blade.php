{{--
    A module's questions on their own page (App\Http\Controllers\PlacePageController):
    every question the student wrote down in the module, as cards across the whole width,
    to filter by status, search and sort (App\Livewire\Workspaces\QuestionBoard). A question
    opens on a page of its own (App\Livewire\Workspaces\QuestionPage).
--}}
<x-layouts.app :title="'Questions · '.$module->title.' · '.$workspace->name" :workspace="$workspace" section="modules">
    <livewire:workspaces.question-board :workspace-id="$workspace->id" :module-id="$module->id" :page="true" />
</x-layouts.app>
