{{--
    One class (App\Http\Controllers\PlacePageController). The page keeps one
    recording and one note. Transcription comes later.
--}}
<x-layouts.app :title="$class->title.' · '.$module->title.' · '.$workspace->name" :workspace="$workspace" section="modules" :module-id="$module->id">
    <div class="page-frame space-y-6">
        <x-page :title="$class->title" :back-href="route('workspaces.modules.classes', [$workspace->id, $module->id])" back-to="Classes" :eyebrow="$module->title" :context="$class->line()" />
        <x-module.tabs :workspace-id="$workspace->id" :module-id="$module->id" current="classes" :counts="$counts" />
        <livewire:workspaces.class-recorder :workspace-id="$workspace->id" :module-id="$module->id" :class-id="$class->id" />
    </div>
</x-layouts.app>
