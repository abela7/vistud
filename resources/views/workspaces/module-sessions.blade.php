{{--
    A module's study sessions on their own page (App\Http\Controllers\PlacePageController):
    every study session logged or completed in the module, with duration and timeline.
--}}
<x-layouts.app :title="'Study sessions · '.$module->title.' · '.$workspace->name" :workspace="$workspace" section="modules">
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="space-y-3">
            <x-back :href="route('workspaces.modules.show', [$workspace->id, $module->id])" :to="$module->title" />
            <nav aria-label="Path" class="crumbs">
                <ol role="list">
                    <li><a href="{{ route('workspaces.show', [$workspace->id, 'modules']) }}">Modules</a></li>
                    <li><a href="{{ route('workspaces.modules.show', [$workspace->id, $module->id]) }}">{{ $module->title }}</a></li>
                    <li>Study sessions</li>
                </ol>
            </nav>
            <div class="flex items-center gap-3">
                <span class="place-badge ws-colour-green" aria-hidden="true"><x-icon name="history" class="size-5" /></span>
                <h1 class="text-2xl font-semibold tracking-tight break-words sm:text-3xl">Study sessions</h1>
            </div>
        </div>
        <livewire:workspaces.module-sessions :workspace-id="$workspace->id" :module-id="$module->id" />
        <livewire:workspaces.study-time :workspace-id="$workspace->id" />
    </div>
</x-layouts.app>
