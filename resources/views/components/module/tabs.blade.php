{{--
    A module's tabs (docs/specs/vistud-2-blueprint.md §3.5.3): Topics · Files · Notes · Questions · Sessions. The first
    three are the module's page (`?tab=`); Questions and Sessions are its pages of their own, shown with the same strip
    so the module reads as one place. `current` is the tab that is open; the counts are quiet numbers beside the names.
--}}
@props(['workspaceId', 'moduleId', 'current' => 'folders', 'counts' => []])
@php
    $tabs = [
        'folders' => ['Folders', route('workspaces.modules.show', [$workspaceId, $moduleId]), 'folder'],
        'files' => ['Files', route('workspaces.modules.show', [$workspaceId, $moduleId, 'tab' => 'files']), 'file-text'],
        'notes' => ['Notes', route('workspaces.modules.show', [$workspaceId, $moduleId, 'tab' => 'notes']), 'notebook-pen'],
        'topics' => ['Topics', route('workspaces.modules.show', [$workspaceId, $moduleId, 'tab' => 'topics']), 'book-open'],
        'questions' => ['Questions', route('workspaces.modules.questions', [$workspaceId, $moduleId]), 'circle-help'],
        'sessions' => ['Sessions', route('workspaces.modules.sessions', [$workspaceId, $moduleId]), 'timer'],
    ];
@endphp
<nav aria-label="This module" {{ $attributes->class('page-tabs') }}>
    @foreach ($tabs as $key => [$label, $url, $icon])
        <a href="{{ $url }}" class="page-tab no-underline" @if ($key === $current) aria-current="page" @endif>
            <x-icon :name="$icon" class="size-4 shrink-0" />
            <span>{{ $label }}</span>@if (($counts[$key] ?? 0) > 0)<span class="tab-count">{{ $counts[$key] }}</span>@endif
        </a>
    @endforeach
</nav>
