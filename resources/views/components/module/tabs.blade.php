{{--
    A module's tabs (docs/specs/vistud-2-blueprint.md §3.5.3): Topics · Files · Notes · Questions · Sessions. The first
    three are the module's page (`?tab=`); Questions and Sessions are its pages of their own, shown with the same strip
    so the module reads as one place. `current` is the tab that is open; the counts are quiet numbers beside the names.
--}}
@props(['workspaceId', 'moduleId', 'current' => 'topics', 'counts' => []])
@php
    $tabs = [
        'topics' => ['Topics', route('workspaces.modules.show', [$workspaceId, $moduleId])],
        'files' => ['Files', route('workspaces.modules.show', [$workspaceId, $moduleId, 'tab' => 'files'])],
        'notes' => ['Notes', route('workspaces.modules.show', [$workspaceId, $moduleId, 'tab' => 'notes'])],
        'questions' => ['Questions', route('workspaces.modules.questions', [$workspaceId, $moduleId])],
        'sessions' => ['Sessions', route('workspaces.modules.sessions', [$workspaceId, $moduleId])],
    ];
@endphp
<nav aria-label="This module" {{ $attributes->class('page-tabs') }}>
    @foreach ($tabs as $key => [$label, $url])
        <a href="{{ $url }}" class="page-tab" @if ($key === $current) aria-current="page" @endif>
            {{ $label }}@if (($counts[$key] ?? 0) > 0)<span class="tab-count">{{ $counts[$key] }}</span>@endif
        </a>
    @endforeach
</nav>
