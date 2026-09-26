{{--
    The admin Accounts page (WP6; ADR 0003 §10.1): invitations, with the
    page heading, then the account list. Each is a Livewire component.
--}}
<x-layouts.app area="admin" title="Accounts">
    <div class="mx-auto max-w-5xl space-y-8">
        <livewire:admin.accounts.invitations />
        <livewire:admin.accounts.index />
    </div>
</x-layouts.app>
