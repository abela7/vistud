{{--
    The page frame for signed-out screens: login, two-factor challenge,
    password reset and invitation acceptance (DESIGN.md §7.1).
--}}
@props(['title'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-theme="{{ config('vistud.appearance.light') }}"
    data-theme-light="{{ config('vistud.appearance.light') }}"
    data-theme-dark="{{ config('vistud.appearance.dark') }}"
    data-appearance="system">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-dvh bg-canvas text-fg antialiased">
    {{ $slot }}
</body>
</html>
