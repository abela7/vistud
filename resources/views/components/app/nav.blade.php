{{--
    The main navigation list, shared by the desktop sidebar and the slide-in
    menu (DESIGN.md §6.1). An item is [label, route, icon], plus optionally
    the route pattern that marks it current (for pages under it).
--}}
@props(['items'])
<ul class="nav-list">
    @foreach ($items as $item)
        @php [$label, $route, $icon] = $item; @endphp
        <li>
            <a href="{{ route($route) }}" class="nav-item" title="{{ $label }}" @if (request()->routeIs($item[3] ?? $route)) aria-current="page" @endif>
                <x-icon :name="$icon" />
                <span class="nav-label">{{ $label }}</span>
            </a>
        </li>
    @endforeach
</ul>
