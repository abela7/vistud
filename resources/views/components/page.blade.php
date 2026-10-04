{{--
    The top of a page (DESIGN.md §5.4, docs/specs/vistud-2-blueprint.md §3.9): one row, so the page starts with its
    content. Back is a round button to the page above (`back-href`, `back-to`), the title sits beside it with a small
    line above (`eyebrow`, usually the course's name) and one line of what the page is for below (`context`, 60
    characters at most). The page's one primary action goes in the `action` slot; everything else goes in the `menu`
    slot as `.menu-item` links and buttons, behind a ⋯ button (kept open or shut by the browser when a Livewire component
    that holds the page's top draws it again).
--}}
@props(['title', 'backHref' => null, 'backTo' => null, 'eyebrow' => null, 'context' => null])
<div {{ $attributes->class('section-header') }}>
    @if ($backHref !== null)
        <x-back :href="$backHref" :to="$backTo" compact />
    @endif
    <div class="section-header-text">
        @if ($eyebrow !== null)
            <p class="section-header-eyebrow">{{ $eyebrow }}</p>
        @endif
        <h1 class="section-header-title">{{ $title }}</h1>
        @if ($context !== null)
            <p class="section-header-context">{{ $context }}</p>
        @endif
    </div>
    @if (isset($action) || isset($menu))
        <div class="section-header-actions">
            @isset($menu)
                <div class="relative shrink-0">
                    <button type="button" class="topbar-button" wire:ignore.self data-menu-button aria-controls="page-menu" aria-expanded="false" title="More">
                        <x-icon name="ellipsis" class="size-5" /><span class="sr-only">More for {{ $title }}</span>
                    </button>
                    <div id="page-menu" class="row-menu" wire:ignore.self data-menu-panel popover="manual" hidden>{{ $menu }}</div>
                </div>
            @endisset
            @isset($action){{ $action }}@endisset
        </div>
    @endif
</div>
