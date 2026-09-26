{{-- The <head> every page shares: theme before first paint, icons, assets (DESIGN.md §3.6). --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<title>{{ $title }} · ViStud</title>
@include('partials.theme-script')
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('brand/favicon-32.png') }}">
<link rel="apple-touch-icon" href="{{ asset('brand/apple-touch-icon-180.png') }}">
@vite(['resources/css/app.css', 'resources/js/app.js'])
