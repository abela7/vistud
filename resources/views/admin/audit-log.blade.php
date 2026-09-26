{{-- The admin audit log (WP6; ADR 0003 §10.4). Read-only; the Livewire component does the work. --}}
<x-layouts.app area="admin" title="Audit log">
    <div class="mx-auto max-w-5xl space-y-6">
        <div class="space-y-2">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Audit log</h1>
            <p class="max-w-2xl text-fg-muted">
                A permanent record of sign-ins to the admin area and every change to accounts. Nobody can edit or delete it.
            </p>
        </div>
        <livewire:admin.audit-log.index />
    </div>
</x-layouts.app>
