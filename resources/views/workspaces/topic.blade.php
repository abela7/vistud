{{--
    A topic on a dedicated page of its own (App\Http\Controllers\TopicPageController):
    Progress tracking, topic questions, dedicated AI tutor, notes & files, and topic settings.
--}}
@php
    $cleanName = \App\Study\Topics::cleanMarkdown($topic->name);
@endphp
<x-layouts.app :title="$cleanName.' · Topics · '.$workspace->name" :workspace="$workspace" :section="$topic->moduleId !== null ? 'modules' : 'progress'" :module-id="$topic->moduleId" :folder-id="$topic->folderId">
    <div class="mx-auto max-w-6xl px-4 sm:px-6 py-6">
        <livewire:workspaces.topic-page
            :workspace-id="$workspace->id"
            :topic-id="$topic->id" />
        <livewire:workspaces.ai-assist :workspace-id="$workspace->id" key="ai-assist" />
    </div>
</x-layouts.app>
