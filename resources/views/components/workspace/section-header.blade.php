{{--
    A workspace section's heading (the owner's review, 2026-09-29): one row, so the page starts with its
    content. Back is a round button (to the course's Home), the workspace's name sits small above the section's,
    an optional count follows the title, and the section's own actions go on the right. A page inside a
    section (a module's questions, a question) says where Back goes (`back-href`, `back-to`) and what
    sits above its title (`eyebrow`, the workspace's name by default).
--}}
@props(['workspace', 'title', 'count' => null, 'countLabel' => null, 'backHref' => null, 'backTo' => 'Home', 'eyebrow' => null])
<div {{ $attributes->class('section-header') }}>
    <x-back :href="$backHref ?? route('workspaces.show', $workspace->id)" :to="$backTo" compact />
    <div class="section-header-text">
        <p class="section-header-eyebrow">{{ $eyebrow ?? $workspace->name }}</p>
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
