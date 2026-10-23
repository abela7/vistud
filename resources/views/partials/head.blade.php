{{--
    The <head> every page shares: theme before first paint, icons, assets (DESIGN.md §3.6), and how the page shows up
    outside ViStud (App\Appearance\Share): its description, a shared link's preview, and whether search engines may
    list it ($index: only the signed-out pages anyone may see; every other page says noindex).
--}}
@php
    $share = \App\Appearance\Share::class;
    $index ??= false;
    $description ??= $share::DESCRIPTION;
    $shareTitle ??= $share::NAME.': '.$share::TAGLINE;
    $bar = $share::barColors();
@endphp
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<title>{{ $title }} · ViStud</title>
<meta name="description" content="{{ $description }}">
<meta name="robots" content="{{ $index ? 'index, follow' : 'noindex, nofollow' }}">
@if ($index)
    <link rel="canonical" href="{{ url()->current() }}">
@endif
<meta name="application-name" content="ViStud">
<meta name="apple-mobile-web-app-title" content="ViStud">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="{{ $bar['light'] }}">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="{{ $bar['dark'] }}">
{{--
    A shared link's preview (WhatsApp, Telegram, Slack, Facebook, LinkedIn, X): nothing of a student's. Only a listed
    page names its own address: a private page's head never depends on what was asked for, so another student's course
    stays exactly as missing as one that does not exist.
--}}
<meta property="og:site_name" content="ViStud">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $shareTitle }}">
<meta property="og:description" content="{{ $description }}">
@if ($index)
    <meta property="og:url" content="{{ url()->current() }}">
@endif
<meta property="og:image" content="{{ asset($share::IMAGE) }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="{{ $share::IMAGE_ALT }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $shareTitle }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ asset($share::IMAGE) }}">
<meta name="twitter:image:alt" content="{{ $share::IMAGE_ALT }}">
@include('partials.theme-script')
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('brand/favicon-32.png') }}">
<link rel="apple-touch-icon" href="{{ asset('brand/apple-touch-icon-180.png') }}">
<link rel="manifest" href="{{ route('manifest') }}">
@vite(['resources/css/app.css', 'resources/js/app.js'])
