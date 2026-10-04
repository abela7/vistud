{{--
    A module's questions on their own page (App\Http\Controllers\PlacePageController), behind the module's tabs: every question
    the student wrote down in the module, as cards across the whole width, to filter by status, search and sort
    (App\Livewire\Workspaces\QuestionBoard). A question opens on a page of its own (App\Livewire\Workspaces\QuestionPage).
--}}
<x-layouts.app :title="'Questions · '.$module->title.' · '.$workspace->name" :workspace="$workspace" section="modules">
    <div class="mx-auto max-w-6xl space-y-6">
        <x-page :title="$module->title" :back-href="route('workspaces.show', [$workspace->id, 'modules'])" back-to="Modules" :eyebrow="$workspace->name" />
        <x-module.tabs :workspace-id="$workspace->id" :module-id="$module->id" current="questions" :counts="$counts" />
        <livewire:workspaces.question-board :workspace-id="$workspace->id" :module-id="$module->id" :page="true" />
        <livewire:workspaces.ai-assist :workspace-id="$workspace->id" key="ai-assist" />
    </div>
</x-layouts.app>
