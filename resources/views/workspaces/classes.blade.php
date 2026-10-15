{{--
    A module's classes on their own page (App\Http\Controllers\PlacePageController).
--}}
<x-layouts.app :title="'Classes · '.$module->title.' · '.$workspace->name" :workspace="$workspace" section="modules" :module-id="$module->id">
    <div class="page-frame space-y-6">
        <x-page :title="$module->title" :back-href="route('workspaces.show', [$workspace->id, 'modules'])" back-to="Modules" :eyebrow="$workspace->name" context="Classes you sat in this module" />
        <x-module.tabs :workspace-id="$workspace->id" :module-id="$module->id" current="classes" :counts="$counts" />
        <livewire:workspaces.module-classes :workspace-id="$workspace->id" :module-id="$module->id" />
    </div>
</x-layouts.app>
