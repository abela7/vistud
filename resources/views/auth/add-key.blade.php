<x-layouts.auth title="Add your AI key">
    <main class="mx-auto max-w-md space-y-5 p-8">
        <h1 class="text-2xl font-semibold">Add your AI key</h1>
        <p>Your AI uses your own account. <a href="https://openrouter.ai/settings/keys" target="_blank" rel="noopener" class="text-link">Get an OpenRouter key</a>.</p>
        <form method="POST" action="{{ route('joining.key.store') }}" class="space-y-5">@csrf
            <x-field name="key" label="Your OpenRouter key" type="password" autocomplete="off" required />
            <x-button type="submit" variant="primary">Save my key</x-button>
        </form>
    </main>
</x-layouts.auth>
