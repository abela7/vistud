{{--
    A module's questions on their own page (App\Http\Controllers\PlacePageController):
    every question the student wrote down in the module, to filter by status,
    search and sort (App\Livewire\Workspaces\QuestionBoard).
--}}
<x-layouts.app :title="'Questions · '.$module->title.' · '.$workspace->name" :workspace="$workspace" section="modules">
    <div class="mx-auto max-w-4xl space-y-6">
        <div class="space-y-1">
            <nav aria-label="Path" class="crumbs">
                <ol role="list">
                    <li><a href="{{ route('workspaces.show', [$workspace->id, 'modules']) }}">Modules</a></li>
                    <li><a href="{{ route('workspaces.modules.show', [$workspace->id, $module->id]) }}">{{ $module->title }}</a></li>
                    <li>Questions</li>
                </ol>
            </nav>
            <div class="flex items-center gap-3">
                <span class="place-badge ws-colour-blue" aria-hidden="true"><x-icon name="circle-help" class="size-5" /></span>
                <h1 class="text-2xl font-semibold tracking-tight break-words sm:text-3xl">Questions</h1>
            </div>
        </div>
        <livewire:workspaces.question-board :workspace-id="$workspace->id" :module-id="$module->id" :page="true" :ask="$ask" />
    </div>
</x-layouts.app>
