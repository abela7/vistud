{{--
    Not found (WP6). The wording is fixed: the exception message is never shown,
    so a missing page and a hidden one cannot be told apart from the text.
--}}
<x-layouts.auth title="Page not found">
    <div class="grid min-h-dvh grid-cols-[minmax(0,1fr)] grid-rows-[auto_1fr] lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:grid-rows-none">
        <x-auth.brand-panel />

        <main class="surface-subtle flex flex-col items-center px-4 py-8 sm:px-8 sm:py-12 lg:justify-center">
            <div class="w-full max-w-[26rem] space-y-6 sm:card sm:p-8">
                <div class="space-y-1.5">
                    <h1 class="text-2xl font-semibold tracking-tight">Page not found</h1>
                    <p class="text-fg-muted">This page doesn't exist, or it isn't available to you.</p>
                </div>

                <a href="{{ route('home') }}" class="btn btn-primary btn-lg w-full">Go to home</a>
            </div>

            <x-appearance-switcher class="mt-8" />
        </main>
    </div>
</x-layouts.auth>
