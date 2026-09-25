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
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title>{{ $title }} · ViStud</title>
    @include('partials.theme-script')
    <link rel="icon" type="image/png" href="{{ asset('brand/vistud-mark.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-canvas text-fg antialiased">
    {{ $slot }}
</body>
</html>
