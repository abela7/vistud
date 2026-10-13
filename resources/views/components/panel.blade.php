{{--
    A settings panel that is always open: icon, title, one short line of where it stands (the `status` slot), then its
    content. Panels that fold are <x-accordion>; the ones with a result to read after an action are these.
--}}
@props(['id', 'title', 'icon' => 'settings'])
<section id="{{ $id }}" {{ $attributes->class(['acc', 'acc-open']) }} aria-labelledby="{{ $id }}-title">
    <div class="acc-head acc-static">
        <span class="item-icon" aria-hidden="true"><x-icon :name="$icon" class="size-5" /></span>
        <h2 class="acc-text">
            <span id="{{ $id }}-title" class="panel-title">{{ $title }}</span>
            @isset($status)
                <span class="acc-status">{{ $status }}</span>
            @endisset
        </h2>
    </div>
    <div class="acc-body">
        {{ $slot }}
    </div>
</section>
