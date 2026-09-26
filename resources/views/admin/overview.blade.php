{{--
    The admin landing page (WP6; ADR 0003 §10.1). The Overview's system
    health, jobs and storage figures come in a later milestone; for M1 it
    points to the admin tools as they arrive.
--}}
<x-layouts.app area="admin" title="Overview">
    <div class="mx-auto max-w-5xl space-y-8">
        <div class="space-y-2">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Admin overview</h1>
            <p class="max-w-2xl text-fg-muted">
                Admin tools act on accounts and platform settings. They never show anyone's notes or learning history.
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ([
                ['users', 'Accounts', 'See everyone who can log in, suspend or reactivate accounts, and grant or remove admin.', route('admin.accounts')],
                ['scroll-text', 'Audit log', 'A read-only record of sign-ins to the admin area and every account change.', route('admin.audit-log')],
            ] as [$icon, $name, $description, $href])
                <section class="relative rounded-xl border border-border bg-surface-raised p-5">
                    <div class="flex items-start gap-3">
                        <span class="rounded-lg bg-accent-subtle p-2 text-accent-contrast"><x-icon :name="$icon" /></span>
                        <div class="space-y-1">
                            <h2 class="font-semibold">
                                @if ($href)
                                    <a href="{{ $href }}" class="after:absolute after:inset-0 after:rounded-xl hover:underline">{{ $name }}</a>
                                @else
                                    {{ $name }}
                                @endif
                            </h2>
                            <p class="text-sm text-fg-muted">{{ $description }}</p>
                            @unless ($href)
                                <p class="text-sm font-medium text-fg-subtle">Coming next</p>
                            @endunless
                        </div>
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</x-layouts.app>
