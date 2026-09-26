{{--
    The signed-in frame for both areas (ADR 0003 §4, §10 and §11;
    DESIGN.md §6.1 and §7.2): a top bar on the header gradient, a sidebar on
    desktop that collapses to icons, and the same navigation as a slide-in
    menu on tablets and phones (the owner's decision for now; ADR 0003 §11's
    phone bottom bar is revisited when Notes, Review and Calendar exist).
    The admin area adds the permanent Admin marker and its own items.
    Inside a study workspace (`workspace` and `section` props) the sidebar
    holds that workspace's switcher and sections, and phones get a bottom tab
    bar of its sections. Optional slots (used by the design mockups):
    `sidebar` replaces the navigation, and `tabbar` adds a bottom bar.
--}}
@props(['title', 'area' => 'student', 'workspace' => null, 'section' => null])
@php
    $principal = app(\App\Identity\PrincipalFactory::class)->fromRequest(request());
    $isAdmin = $principal->hasRole(\App\Platform\Access\Role::Admin);
    $isStudent = $principal->hasRole(\App\Platform\Access\Role::Student);
    $user = auth()->user();
    $items = $area === 'admin'
        ? [['Overview', 'admin.overview', 'layout-dashboard'], ['Accounts', 'admin.accounts', 'users'], ['Audit log', 'admin.audit-log', 'scroll-text']]
        : [['Home', 'home', 'house'], ['Security', 'two-factor.setup', 'shield-check']];
    // A student's own workspaces, for the sidebar and the switcher.
    $workspaces = $area === 'student' && $isStudent ? app(\App\Study\Workspaces::class)->list($principal) : null;
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
    @if ($isStudent)
        {{-- Whose drafts this browser may hold and sync (resources/js/note/). --}}
        <meta name="vistud-account" content="{{ $user->id }}">
    @endif
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
                    <form method="POST" action="{{ route('area.switch', 'student') }}">
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
                <form method="POST" action="{{ route('logout') }}" data-busy-on-submit @if ($isStudent) data-logout-form @endif>
                    @csrf
                    <button type="submit" class="menu-item"><x-icon name="log-out" class="size-4" />Log out</button>
                </form>
            </div>
        </div>

        <div class="app-body">
            <aside class="app-sidebar">
                <nav aria-label="Main">
                    @isset($sidebar)
                        {{ $sidebar }}
                    @elseif ($workspace)
                        <x-workspace.nav :workspace="$workspace" :workspaces="$workspaces ?? []" :section="$section" menu-id="ws-menu-sidebar" />
                    @elseif ($workspaces !== null)
                        <x-app.student-nav :workspaces="$workspaces" />
                    @else
                        <x-app.nav :items="$items" />
                    @endisset
                </nav>
                <button type="button" class="nav-item sidebar-toggle" data-sidebar-toggle title="Collapse sidebar">
                    <x-icon name="panel-left" />
                    <span class="nav-label">Collapse sidebar</span>
                </button>
            </aside>

            <main id="main" @class(['app-main', 'has-tabbar' => isset($tabbar) || $workspace]) tabindex="-1">
                {{ $slot }}
            </main>
        </div>

        @isset($tabbar)
            <nav class="app-tabbar" aria-label="Sections">{{ $tabbar }}</nav>
        @elseif ($workspace)
            <nav class="app-tabbar" aria-label="{{ $workspace->name }} sections"><x-workspace.tabs :workspace="$workspace" :section="$section" /></nav>
        @endisset
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
                @isset($sidebar)
                    {{ $sidebar }}
                @elseif ($workspace)
                    <x-workspace.nav :workspace="$workspace" :workspaces="$workspaces ?? []" :section="$section" menu-id="ws-menu-drawer" />
                @elseif ($workspaces !== null)
                    <x-app.student-nav :workspaces="$workspaces" />
                @else
                    <x-app.nav :items="$items" />
                @endisset
            </nav>
        </div>
    </dialog>

    @if ($isStudent)
        {{-- Logging out with unsaved note drafts on this device (resources/js/note/logout.js). --}}
        <dialog class="modal" aria-labelledby="logout-dialog-title" data-logout-dialog>
            <div class="modal-panel">
                <div class="modal-head">
                    <h2 id="logout-dialog-title" class="min-w-0 flex-1 text-lg font-semibold">Unsaved changes on this device</h2>
                    <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-data x-on:click="$el.closest('dialog').close()">
                        <x-icon name="x" />
                    </button>
                </div>
                <div class="space-y-3 px-5 pt-2">
                    <p data-logout-summary></p>
                    <p class="text-sm text-fg-muted">If you keep them here, they're saved the next time you log in on this device. Anyone using this browser could read them until then.</p>
                    <div data-logout-problem hidden>
                        <x-alert tone="warning" :live="false"><span data-logout-problem-text></span></x-alert>
                    </div>
                </div>
                <div class="modal-actions sm:flex-col">
                    <x-button variant="danger" data-logout-choice="discard">Discard them and log out</x-button>
                    <x-button data-logout-choice="keep">Keep them here and log out</x-button>
                    <x-button variant="primary" data-logout-choice="sync">Save them and log out</x-button>
                </div>
            </div>
        </dialog>

        {{-- A short message from the page's own scripts, like a draft removed because its note was deleted. --}}
        <div class="app-notice" data-app-notice hidden>
            <x-icon name="info" class="size-5 shrink-0" />
            <p class="min-w-0 flex-1" data-app-notice-text></p>
            <button type="button" class="topbar-button -my-1 shrink-0" aria-label="Dismiss" data-app-notice-close><x-icon name="x" class="size-4" /></button>
        </div>
        <p class="sr-only" role="status" data-app-notice-live></p>
    @endif
</body>
</html>
