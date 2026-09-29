{{--
    The way back up (the owner's review, 2026-09-28): every page but Home has
    one, above its title, naming where it goes. Coming from that page, it goes
    back in the browser's history, so the scroll position is kept
    (resources/js/back.js); otherwise it opens it. `compact` is a round button
    beside a title (x-workspace.section-header), named for screen readers and
    on hover.
--}}
@props(['href', 'to', 'compact' => false])
<a href="{{ $href }}" {{ $attributes->class(['back-link', 'back-link-compact' => $compact]) }} data-back @if ($compact) title="Back to {{ $to }}" @endif>
    <x-icon name="arrow-left" class="size-4 shrink-0" />
    <span @class(['sr-only' => $compact, 'truncate' => ! $compact])><span class="sr-only">Back to </span>{{ $to }}</span>
</a>
