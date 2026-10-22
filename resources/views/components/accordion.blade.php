{{--
    A panel folded away under its heading: icon, title, one short line of where it stands (the `status` slot), and a
    chevron. It opens on the heading and on `#id` in the address. Whether it starts open is read once, from
    `data-open`, and not from the script: a page update that changes it must not make the panel fold or open by
    itself under the student's hand.
--}}
@props(['id', 'title', 'icon' => 'settings', 'open' => false, 'level' => 2])
<section id="{{ $id }}" {{ $attributes->class(['acc']) }}
    data-open="{{ $open ? '1' : '0' }}"
    x-data="{ open: $el.dataset.open === '1' || location.hash === '#{{ $id }}' }"
    x-on:hashchange.window="if (location.hash === '#{{ $id }}') open = true">
    <h{{ (int) $level === 3 ? 3 : 2 }} class="acc-heading">
        <button type="button" class="acc-head" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-controls="{{ $id }}-body">
            <span class="item-icon" aria-hidden="true"><x-icon :name="$icon" class="size-5" /></span>
            <span class="acc-text">
                <span class="panel-title">{{ $title }}</span>
                @isset($status)
                    <span class="acc-status">{{ $status }}</span>
                @endisset
            </span>
            <x-icon name="chevron-right" class="acc-chevron size-5" />
        </button>
    </h{{ (int) $level === 3 ? 3 : 2 }}>
    <div id="{{ $id }}-body" class="acc-body" x-show="open" x-cloak>
        {{ $slot }}
    </div>
</section>
