{{--
    A note in a window of its own (the owner's review, 2026-09-29): only the
    note, so it sits beside the study material in the main window. No top
    bar, sidebar or Livewire; resources/js/note/editor.js does the rest.
    `workspace` and `section` are taken so a page can switch between this
    and layouts.app with the same attributes.
--}}
@props(['title', 'workspace' => null, 'section' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-theme="{{ config('vistud.appearance.light') }}"
    data-theme-light="{{ config('vistud.appearance.light') }}"
    data-theme-dark="{{ config('vistud.appearance.dark') }}"
    data-appearance="system"
    data-note-full>
<head>
    @include('partials.head', ['title' => $title])
    {{-- Whose drafts this browser may hold and sync (resources/js/note/). --}}
    <meta name="vistud-account" content="{{ auth()->id() }}">
</head>
<body class="bg-canvas text-fg antialiased">
    <main id="main" class="note-window" tabindex="-1">
        {{ $slot }}
    </main>

    <div class="toast-stack" data-toasts role="status" aria-live="polite"></div>
    <template data-toast-template>
        <div class="toast">
            <span class="toast-icon" data-toast-icon="success"><x-icon name="circle-check" class="size-5" /></span>
            <span class="toast-icon" data-toast-icon="info"><x-icon name="info" class="size-5" /></span>
            <p class="toast-text" data-toast-text></p>
            <button type="button" class="toast-close" aria-label="Dismiss" data-toast-close><x-icon name="x" class="size-4" /></button>
        </div>
    </template>
</body>
</html>
