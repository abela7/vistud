{{--
    A folder's tabs, when it is a place to study (docs/specs/vistud-2-blueprint.md, Phase 9): Topics · Files · Notes ·
    Questions · Cards, all on the folder's own page (`?tab=`). `current` is the tab that is open; the counts are quiet
    numbers beside the names, as on a module's tabs.
--}}
@props(['workspaceId', 'folderId', 'current' => 'dashboard', 'counts' => []])
@php
    $tabs = [
        'dashboard' => ['Dashboard', 'layout-grid'],
        'topics' => ['Topics', 'book-open'],
        'files' => ['Files', 'file-text'],
        'notes' => ['Notes', 'notebook-pen'],
        'questions' => ['Questions', 'circle-help'],
        'cards' => ['Cards', 'gallery-vertical-end'],
    ];
@endphp
<nav aria-label="This folder" {{ $attributes->class('page-tabs') }}>
    @foreach ($tabs as $key => [$label, $icon])
        <a href="{{ route('workspaces.folders.show', [$workspaceId, $folderId] + ($key === 'dashboard' ? [] : ['tab' => $key])) }}" class="page-tab no-underline" @if ($key === $current) aria-current="page" @endif>
            <x-icon :name="$icon" class="size-4 shrink-0" />
            <span>{{ $label }}</span>@if (($counts[$key] ?? 0) > 0)<span class="tab-count">{{ $counts[$key] }}</span>@endif
        </a>
    @endforeach
</nav>

