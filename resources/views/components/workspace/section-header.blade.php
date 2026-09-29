{{--
    A workspace section's heading (the owner's review, 2026-09-29): one row, so the page starts with its
    content. Back is a round button (to the Overview), the workspace's name sits small above the section's,
    an optional count follows the title, and the section's own actions go on the right.
--}}
@props(['workspace', 'title', 'count' => null, 'countLabel' => null])
<div {{ $attributes->class('section-header') }}>
    <x-back :href="route('workspaces.show', $workspace->id)" to="Overview" compact />
    <div class="section-header-text">
        <p class="section-header-eyebrow">{{ $workspace->name }}</p>
        <div class="flex min-w-0 items-center gap-2">
            <h1 class="section-header-title">{{ $title }}</h1>
            @if ($count !== null)
                <span class="count-pill" title="{{ $count }} {{ $countLabel }}">{{ $count }}<span class="sr-only"> {{ $countLabel }}</span></span>
            @endif
        </div>
    </div>
    @if ($slot->isNotEmpty())
        <div class="section-header-actions">{{ $slot }}</div>
    @endif
</div>
