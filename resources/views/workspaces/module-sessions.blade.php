{{--
    A module's study sessions on their own page (App\Http\Controllers\PlacePageController), behind the module's tabs:
    every study session logged or completed in the module, with duration and timeline.
--}}
<x-layouts.app :title="'Study sessions · '.$module->title.' · '.$workspace->name" :workspace="$workspace" section="modules">
    <div class="mx-auto max-w-4xl space-y-6">
        <x-page :title="$module->title" :back-href="route('workspaces.show', [$workspace->id, 'modules'])" back-to="Modules" :eyebrow="$workspace->name" />
        <x-module.tabs :workspace-id="$workspace->id" :module-id="$module->id" current="sessions" :counts="$counts" />
        <livewire:workspaces.module-sessions :workspace-id="$workspace->id" :module-id="$module->id" />
        <livewire:workspaces.study-time :workspace-id="$workspace->id" />
    </div>
</x-layouts.app>
