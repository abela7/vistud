{{-- A module's or a folder's own page (App\Http\Controllers\PlacePageController). --}}
<x-layouts.app :title="$title.' · '.$workspace->name" :workspace="$workspace" :section="$section" :module-id="$view === 'module' ? $placeId : null" :folder-id="$view === 'folder' ? $placeId : null">
    <div class="page-frame">
        <livewire:workspaces.contents :workspace-id="$workspace->id" :view="$view" :place-id="$placeId" />
        <livewire:workspaces.study-time :workspace-id="$workspace->id" />
        <livewire:workspaces.ai-assist :workspace-id="$workspace->id" key="ai-assist" />
    </div>
</x-layouts.app>
