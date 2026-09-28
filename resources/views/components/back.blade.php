{{--
    The way back up (the owner's review, 2026-09-28): every page but Home has
    one, above its title, naming where it goes. Coming from that page, it goes
    back in the browser's history, so the scroll position is kept
    (resources/js/back.js); otherwise it opens it.
--}}
@props(['href', 'to'])
<a href="{{ $href }}" {{ $attributes->class('back-link') }} data-back>
    <x-icon name="arrow-left" class="size-4 shrink-0" />
    <span class="truncate"><span class="sr-only">Back to </span>{{ $to }}</span>
</a>
