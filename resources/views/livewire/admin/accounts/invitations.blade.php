{{--
    The top of the admin Accounts page (App\Livewire\Admin\Accounts\Invitations):
    the page heading, the Invite button and its dialog, and the invitations
    nobody has accepted yet. The account list below is its own component.
--}}
@php
    use Illuminate\Support\Carbon;
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-2">
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Accounts</h1>
            <p class="max-w-2xl text-fg-muted">
                Everyone who can log in. You see account details here, never anyone's notes or learning history.
            </p>
        </div>
        <x-button variant="primary" icon="user-plus" class="max-w-full whitespace-normal" x-data x-on:click="document.getElementById('invite-dialog').showModal()">Invite someone</x-button>
    </div>

    <div role="status" aria-live="polite">
        @if ($notice)
            <x-alert tone="success" :live="false">{{ $notice }}</x-alert>
        @endif
    </div>

    @if ($pending !== [])
        <section aria-labelledby="pending-heading" class="space-y-3">
            <h2 id="pending-heading" class="text-sm font-semibold text-fg-muted">Waiting to be accepted ({{ count($pending) }})</h2>
            <ul class="account-list" role="list">
                @foreach ($pending as $invitation)
                    <li wire:key="invitation-{{ $invitation->id }}" class="account-row">
                        <span class="avatar account-avatar" aria-hidden="true"><x-icon name="mail" class="size-4" /></span>
                        <div class="account-who">
                            <p class="font-semibold break-all">{{ $invitation->email }}</p>
                            <p class="text-sm text-fg-muted">
                                Invited {{ Carbon::parse($invitation->createdAt)->diffForHumans() }}{{ $invitation->invitedByName ? ' by '.$invitation->invitedByName : '' }}
                            </p>
                        </div>
                        <div class="account-meta">
                            @if ($invitation->expired)
                                <span class="badge badge-warning"><x-icon name="triangle-alert" class="size-3.5" />Expired</span>
                            @else
                                <span class="text-sm text-fg-muted">Link expires {{ Carbon::parse($invitation->expiresAt)->diffForHumans() }}</span>
                            @endif
                        </div>
                        <x-button variant="ghost" class="account-manage" wire:click="askCancel('{{ $invitation->id }}')">
                            Cancel<span class="sr-only"> the invitation for {{ $invitation->email }}</span>
                        </x-button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <dialog id="invite-dialog" class="modal" aria-labelledby="invite-dialog-title"
        wire:ignore.self
        x-data
        x-on:close="$wire.done()"
        x-on:click="$event.target === $el && $el.close()">
        <div class="modal-panel" wire:key="invite-{{ $issuedTo ?? 'form' }}">
            @if ($issuedTo === null)
                <form wire:submit="invite" novalidate>
                    <div class="modal-head">
                        <div class="min-w-0 flex-1 space-y-1">
                            <h2 id="invite-dialog-title" class="text-lg font-semibold">Invite someone</h2>
                            <p class="text-fg-muted">They get a link to create a student account. You can make them an admin afterwards.</p>
                        </div>
                        <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                            <x-icon name="x" />
                        </button>
                    </div>
                    <div class="px-5 pt-3">
                        <x-field name="email" label="Their email address" type="email" wire:model="email"
                            autocomplete="off" inputmode="email" autocapitalize="none" spellcheck="false" enterkeyhint="go" autofocus />
                    </div>
                    <div class="modal-actions">
                        <x-button x-on:click="$el.closest('dialog').close()">Cancel</x-button>
                        <x-button type="submit" variant="primary" icon="mail" wire:loading.attr="aria-busy" wire:target="invite" busy-label="Creating…">Create invitation link</x-button>
                    </div>
                </form>
            @else
                <div class="space-y-4 px-5 pt-5">
                    <h2 id="invite-dialog-title" class="text-lg font-semibold break-words" tabindex="-1" autofocus x-init="$el.focus()">Invitation for {{ $issuedTo }}</h2>
                    @if ($link)
                        <p class="text-fg-muted">
                            Send them this link yourself, for example in a message. It works once and expires in {{ $ttlHours }} hours.
                        </p>
                        <div class="space-y-1.5">
                            <label for="invite-link" class="field-label">Invitation link</label>
                            <div class="flex flex-wrap gap-2">
                                <input id="invite-link" type="text" class="input input-copy min-w-0 flex-1 font-mono text-sm" value="{{ $link }}" readonly x-on:focus="$el.select()">
                                <x-button variant="secondary" icon="copy" data-copy-target="invite-link" data-copied-label="Copied">Copy link</x-button>
                            </div>
                        </div>
                        <x-alert tone="warning">This is the only time the link is shown. Anyone who has it can create the account.</x-alert>
                    @else
                        <p class="text-fg-muted">
                            The link is only shown once. If you didn't copy it, cancel this invitation and invite them again.
                        </p>
                    @endif
                </div>
                <div class="modal-actions">
                    <x-button variant="primary" x-on:click="$el.closest('dialog').close()">Done</x-button>
                </div>
            @endif
        </div>
    </dialog>

    <dialog id="cancel-invitation-dialog" class="modal" aria-labelledby="cancel-invitation-title"
        wire:ignore.self
        x-data
        x-on:invitation-cancel-open.window="$el.open || $el.showModal()"
        x-on:invitation-cancel-close.window="$el.open && $el.close()"
        x-on:close="$wire.cancelling && $wire.keep()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($cancelling !== null)
            <div class="modal-panel" wire:key="cancel-{{ $cancelling }}">
                <div class="space-y-3 px-5 pt-5">
                    <h2 id="cancel-invitation-title" class="text-lg font-semibold break-words" tabindex="-1" autofocus x-init="$el.focus()">
                        Cancel the invitation{{ $cancellingEmail ? ' for '.$cancellingEmail : '' }}?
                    </h2>
                    <p class="text-fg-muted">Its link stops working at once. You can invite them again later.</p>
                </div>
                <div class="modal-actions">
                    <x-button x-on:click="$el.closest('dialog').close()">Keep it</x-button>
                    <x-button variant="danger" wire:click="cancel" wire:loading.attr="aria-busy" wire:target="cancel" busy-label="Cancelling…">Cancel invitation</x-button>
                </div>
            </div>
        @endif
    </dialog>
</div>
