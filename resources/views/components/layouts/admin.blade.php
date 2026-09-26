{{--
    The admin workspace frame (ADR 0003 §10, DESIGN.md §7.2): the same
    components and theme as the student side, its own navigation, and the
    permanent Admin marker. Every page inside it is behind the admin route
    group (role, 2FA, fresh password on entry).
--}}
@props(['title', 'current' => null])
@php
    $principal = app(\App\Identity\PrincipalFactory::class)->fromRequest(request());
    $links = [
        'overview' => ['Overview', route('admin.overview')],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-theme="{{ config('vistud.appearance.light') }}"
    data-theme-light="{{ config('vistud.appearance.light') }}"
    data-theme-dark="{{ config('vistud.appearance.dark') }}"
    data-appearance="system">
<head>
    @include('partials.head', ['title' => "{$title} · Admin"])
</head>
<body class="min-h-dvh bg-canvas text-fg antialiased">
    <header class="surface-header">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:px-6">
            <a href="{{ route('admin.overview') }}" class="flex items-center gap-3 no-underline" aria-label="ViStud admin overview">
                <x-logo variant="knockout" class="h-7" alt="" />
            </a>
            <x-admin.marker />

            <nav aria-label="Admin" class="order-last flex w-full gap-1 md:order-none md:ml-4 md:w-auto">
                @foreach ($links as $key => [$label, $href])
                    <a href="{{ $href }}" class="nav-link" @if ($current === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>

            <div class="ml-auto flex items-center gap-1">
                @if ($principal->hasRole(\App\Platform\Access\Role::Student))
                    <form method="POST" action="{{ route('workspace.switch', 'student') }}" data-busy-on-submit>
                        @csrf
                        <x-button type="submit" variant="ghost" icon="arrow-left-right" busy-label="Switching…" title="Student area"><span class="max-sm:sr-only">Student area</span></x-button>
                    </form>
                @endif
                <form method="POST" action="{{ route('logout') }}" data-busy-on-submit>
                    @csrf
                    <x-button type="submit" variant="ghost" icon="log-out" busy-label="Logging out…" title="Log out"><span class="max-sm:sr-only">Log out</span></x-button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8 sm:px-6 sm:py-10">
        {{ $slot }}
    </main>
</body>
</html>
