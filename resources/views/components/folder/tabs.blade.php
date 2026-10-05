{{--
    A folder's tabs, when it is a place to study (docs/specs/vistud-2-blueprint.md, Phase 9): Topics · Files · Notes ·
    Questions · Cards, all on the folder's own page (`?tab=`). `current` is the tab that is open; the counts are quiet
    numbers beside the names, as on a module's tabs.
--}}
@props(['workspaceId', 'folderId', 'current' => 'topics', 'counts' => []])
@php
    $tabs = [
        'topics' => 'Topics',
        'files' => 'Files',
        'notes' => 'Notes',
        'questions' => 'Questions',
        'cards' => 'Cards',
    ];
@endphp
<nav aria-label="This folder" {{ $attributes->class('page-tabs') }}>
    @foreach ($tabs as $key => $label)
        <a href="{{ route('workspaces.folders.show', [$workspaceId, $folderId] + ($key === 'topics' ? [] : ['tab' => $key])) }}" class="page-tab" @if ($key === $current) aria-current="page" @endif>
            {{ $label }}@if (($counts[$key] ?? 0) > 0)<span class="tab-count">{{ $counts[$key] }}</span>@endif
        </a>
    @endforeach
</nav>
