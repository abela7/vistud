{{-- A study session's page (App\Http\Controllers\SessionPageController). --}}
<x-layouts.app :title="'Study session · '.$workspace->name" :workspace="$workspace" section="overview">
    <div class="mx-auto max-w-7xl">
        <livewire:workspaces.study-session :workspace-id="$workspace->id" :session-id="$sessionId" />
    </div>
</x-layouts.app>
