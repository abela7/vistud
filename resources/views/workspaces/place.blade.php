{{-- A module's or a folder's own page (App\Http\Controllers\PlacePageController). --}}
<x-layouts.app :title="$title.' · '.$workspace->name" :workspace="$workspace" :section="$section">
    <div class="mx-auto max-w-6xl">
        <livewire:workspaces.contents :workspace-id="$workspace->id" :view="$view" :place-id="$placeId" />
        <livewire:workspaces.study-time :workspace-id="$workspace->id" />
    </div>
</x-layouts.app>
