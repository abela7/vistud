<x-layouts.auth title="Confirm your email">
    <main class="mx-auto max-w-md space-y-5 p-8">
        <h1 class="text-2xl font-semibold">Confirm your email</h1>
        <p>Open the link we sent to {{ auth()->user()->email }}.</p>
        @if (session('status'))<p role="status">A fresh link is on its way.</p>@endif
        <form method="POST" action="{{ route('verification.send') }}">@csrf <x-button type="submit">Send a new link</x-button></form>
        <form method="POST" action="{{ route('logout') }}">@csrf <x-button type="submit" variant="ghost">Log out</x-button></form>
    </main>
</x-layouts.auth>
