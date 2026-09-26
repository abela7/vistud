{{--
    The signed-in frame for both workspaces (ADR 0003 §4, §10 and §11;
    DESIGN.md §6.1 and §7.2): a top bar on the header gradient, a sidebar on
    desktop that collapses to icons, and the same navigation as a slide-in
    menu on tablets and phones (the owner's decision for now; ADR 0003 §11's
    phone bottom bar is revisited when Notes, Review and Calendar exist).
    The admin workspace adds the permanent Admin marker and its own items.
--}}
@props(['title', 'area' => 'student'])
@php
    $principal = app(\App\Identity\PrincipalFactory::class)->fromRequest(request());
    $isAdmin = $principal->hasRole(\App\Platform\Access\Role::Admin);
    $isStudent = $principal->hasRole(\App\Platform\Access\Role::Student);
    $user = auth()->user();
    $items = $area === 'admin'
        ? [['Overview', 'admin.overview', 'layout-dashboard'], ['Accounts', 'admin.accounts', 'users'], ['Audit log', 'admin.audit-log', 'scroll-text']]
        : array_values(array_filter([
            ['Home', 'home', 'house'],
            $isStudent ? ['Journal', 'journal.index', 'notebook-text', 'journal.*'] : null,
            ['Security', 'two-factor.setup', 'shield-check'],
        ]));
    $homeRoute = $area === 'admin' ? 'admin.overview' : 'home';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-theme="{{ config('vistud.appearance.light') }}"
    data-theme-light="{{ config('vistud.appearance.light') }}"
    data-theme-dark="{{ config('vistud.appearance.dark') }}"
    data-appearance="system">
<head>
    @include('partials.head', ['title' => $area === 'admin' ? "{$title} · Admin" : $title])
</head>
<body class="bg-canvas text-fg antialiased">
    <a href="#main" class="skip-link">Skip to content</a>

    <div class="app-shell">
        <header class="app-topbar surface-header">
            <button type="button" class="topbar-button xl:hidden" data-drawer-open aria-controls="app-drawer" aria-expanded="false" aria-label="Open menu">
                <x-icon name="menu" />
            </button>
            <a href="{{ route($homeRoute) }}" class="app-brand" aria-label="{{ $area === 'admin' ? 'ViStud admin overview' : 'ViStud home' }}">
                <x-logo variant="knockout" class="h-7" alt="" />
            </a>
            @if ($area === 'admin')
                <x-admin.marker />
            @endif

            <button type="button" class="account-button ml-auto" data-menu-button aria-controls="account-menu" aria-expanded="false">
                <x-avatar :name="$user->name" />
                <span class="max-md:sr-only">{{ $user->name }}</span>
                <x-icon name="chevron-down" class="size-4" />
            </button>
        </header>

        {{-- Outside the header, so it keeps the calm surface's colours. --}}
        <div id="account-menu" class="account-menu" data-menu-panel hidden>
            <div class="px-3 pt-2 pb-3">
                <p class="font-semibold">{{ $user->name }}</p>
                <p class="text-sm text-fg-muted">{{ $user->email }}</p>
            </div>
            <div class="border-t border-divider py-1.5">
                <a href="{{ route('two-factor.setup') }}" class="menu-item"><x-icon name="shield-check" class="size-4" />Security</a>
                @if ($area === 'student' && $isAdmin)
                    <a href="{{ route('admin.overview') }}" class="menu-item"><x-icon name="shield" class="size-4" />Admin area</a>
                @elseif ($area === 'admin' && $isStudent)
                    <form method="POST" action="{{ route('workspace.switch', 'student') }}">
                        @csrf
                        <button type="submit" class="menu-item"><x-icon name="arrow-left-right" class="size-4" />Student area</button>
                    </form>
                @endif
            </div>
            <div class="border-t border-divider px-3 py-3">
                <p class="mb-2 text-xs font-semibold text-fg-muted">Appearance</p>
                <x-appearance-switcher />
            </div>
            <div class="border-t border-divider pt-1.5">
                <form method="POST" action="{{ route('logout') }}" data-busy-on-submit>
                    @csrf
                    <button type="submit" class="menu-item"><x-icon name="log-out" class="size-4" />Log out</button>
                </form>
            </div>
        </div>

        <div class="app-body">
            <aside class="app-sidebar">
                <nav aria-label="Main">
                    <x-app.nav :items="$items" />
                </nav>
                <button type="button" class="nav-item sidebar-toggle" data-sidebar-toggle title="Collapse sidebar">
                    <x-icon name="panel-left" />
                    <span class="nav-label">Collapse sidebar</span>
                </button>
            </aside>

            <main id="main" class="app-main" tabindex="-1">
                {{ $slot }}
            </main>
        </div>
    </div>

    <dialog id="app-drawer" class="drawer" aria-label="Menu">
        <div class="drawer-panel">
            <div class="drawer-head surface-header">
                <x-logo variant="knockout" class="h-7" alt="ViStud" />
                <button type="button" class="topbar-button" data-drawer-close aria-label="Close menu">
                    <x-icon name="x" />
                </button>
            </div>
            @if ($area === 'admin')
                <div class="px-4 pt-4"><x-admin.marker /></div>
            @endif
            <nav aria-label="Main" class="p-3">
                <x-app.nav :items="$items" />
            </nav>
        </div>
    </dialog>
</body>
</html>
