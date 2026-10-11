{{-- A study session's page (App\Http\Controllers\SessionPageController). --}}
<x-layouts.app :title="'Study session · '.$workspace->name" :workspace="$workspace" :section="$section">
    <livewire:workspaces.study-session :workspace-id="$workspace->id" :session-id="$sessionId" />
</x-layouts.app>
