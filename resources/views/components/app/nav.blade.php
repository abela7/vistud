{{-- The main navigation list, shared by the desktop sidebar and the slide-in menu (DESIGN.md §6.1). --}}
@props(['items'])
<ul class="nav-list">
    @foreach ($items as [$label, $route, $icon])
        <li>
            <a href="{{ route($route) }}" class="nav-item" title="{{ $label }}" @if (request()->routeIs($route)) aria-current="page" @endif>
                <x-icon :name="$icon" />
                <span class="nav-label">{{ $label }}</span>
            </a>
        </li>
    @endforeach
</ul>
