{{--
    The account list on the admin Accounts page (App\Livewire\Admin\Accounts\Index). A list of
    accounts, each opening a dialog with the account's details and actions;
    an action asks for confirmation in the same dialog before it runs.
    Details only, never learning content (ADR 0003 §10.2).
--}}
@php
    use Illuminate\Support\Carbon;
    use App\Identity\AccountStatus;
    use App\Platform\Access\Role;

    $date = fn (?string $iso) => $iso === null ? null : Carbon::parse($iso)->format('j M Y');
    $lastActive = fn (?string $iso) => $iso === null ? 'Not signed in' : 'Active '.Carbon::parse($iso)->diffForHumans();

    $actions = [
        'suspend' => ['Suspend account', 'ban'],
        'reactivate' => ['Reactivate account', 'user-check'],
        'grant-admin' => ['Make admin', 'shield-plus'],
        'revoke-admin' => ['Remove admin', 'shield-minus'],
        'reset-two-factor' => ['Reset two-factor authentication', 'key-round'],
    ];
@endphp
<div class="space-y-6">
    <div role="status" aria-live="polite">
        @if ($notice)
            <x-alert tone="success" :live="false">{{ $notice }}</x-alert>
        @endif
    </div>

    <section aria-labelledby="accounts-heading" class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="w-full max-w-sm space-y-1.5">
                <label for="account-search" class="field-label">Search accounts</label>
                <input id="account-search" type="search" class="input" placeholder="Name or email" autocomplete="off" spellcheck="false"
                    wire:model.live.debounce.300ms="search">
            </div>
            <h2 id="accounts-heading" class="text-sm font-semibold text-fg-muted">
                @if ($hasMore)
                    Showing the first {{ count($accounts) }} {{ $search === '' ? 'accounts' : 'matches' }}
                @elseif ($search !== '')
                    {{ count($accounts) }} {{ Str::plural('match', count($accounts)) }}
                @else
                    {{ count($accounts) }} {{ Str::plural('account', count($accounts)) }}
                @endif
            </h2>
        </div>

        @if ($accounts === [])
            <p class="rounded-xl border border-dashed border-border-strong px-5 py-8 text-center text-fg-muted">
                No accounts match “{{ $search }}”.
            </p>
        @endif

        <ul @class(['account-list', 'hidden' => $accounts === []]) role="list">
            @foreach ($accounts as $account)
                @php
                    $isAdmin = in_array(Role::Admin, $account->roles, true);
                    $isStudent = in_array(Role::Student, $account->roles, true);
                @endphp
                <li wire:key="account-{{ $account->id }}" class="account-row">
                    <x-avatar :name="$account->name" class="account-avatar" />
                    <div class="account-who">
                        <p class="flex flex-wrap items-center gap-x-2 font-semibold">
                            <span class="break-words">{{ $account->name }}</span>
                            @if ($account->id === $me)
                                <span class="badge badge-accent">You</span>
                            @endif
                        </p>
                        <p class="text-sm break-all text-fg-muted">{{ $account->email }}</p>
                    </div>
                    <div class="account-meta">
                        <ul class="flex flex-wrap gap-1.5" role="list" aria-label="Roles and status">
                            @if ($isAdmin)
                                <li class="badge badge-admin"><x-icon name="shield" class="size-3.5" />Admin</li>
                            @endif
                            @if ($isStudent)
                                <li class="badge badge-neutral">Student</li>
                            @endif
                            @if ($account->status === AccountStatus::Suspended)
                                <li class="badge badge-warning"><x-icon name="ban" class="size-3.5" />Suspended</li>
                            @elseif ($account->status === AccountStatus::Deleted)
                                <li class="badge badge-danger"><x-icon name="circle-alert" class="size-3.5" />Deleted</li>
                            @endif
                            @if ($isAdmin && ! $account->twoFactorConfirmed)
                                <li class="badge badge-warning"><x-icon name="triangle-alert" class="size-3.5" />No two-factor</li>
                            @endif
                        </ul>
                        <p class="text-sm text-fg-muted">{{ $lastActive($account->lastActiveAt) }}</p>
                    </div>
                    <x-button class="account-manage" wire:click="open('{{ $account->id }}')">
                        Manage<span class="sr-only"> {{ $account->name }}</span>
                    </x-button>
                </li>
            @endforeach
        </ul>

        @if ($hasMore)
            <x-button wire:click="showMore" wire:loading.attr="aria-busy" wire:target="showMore" busy-label="Loading…">Show more</x-button>
        @endif
    </section>

    <dialog id="account-dialog" class="modal" aria-labelledby="account-dialog-title"
        wire:ignore.self
        x-data
        x-on:account-dialog-open.window="$el.open || $el.showModal()"
        x-on:account-dialog-close.window="$el.open && $el.close()"
        x-on:close="$wire.selectedId && $wire.dismiss()"
        x-on:click="$event.target === $el && $el.close()">
        @if ($selected)
            @php
                $selectedIsAdmin = in_array(Role::Admin, $selected->roles, true);
                $name = $selected->name;
                $confirmations = [
                    'suspend' => ["Suspend {$name}?", 'They’re logged out everywhere and can’t log in until the account is reactivated. Nothing in the account is deleted.', 'danger'],
                    'reactivate' => ["Reactivate {$name}?", 'They can log in again with their existing password and two-factor settings.', 'primary'],
                    'grant-admin' => ["Make {$name} an admin?", 'They’ll be able to open the admin area, manage accounts and read the audit log. They must set up two-factor authentication before their first visit. Admins never see anyone’s notes or learning history.', 'primary'],
                    'revoke-admin' => ["Remove admin from {$name}?", 'They lose access to the admin area straight away. Their student account, if they have one, is not affected.', 'danger'],
                    'reset-two-factor' => ["Reset two-factor authentication for {$name}?", 'Their authenticator app and recovery codes stop working, and they’re logged out everywhere. They must set up two-factor authentication again before they can open the admin area.', 'danger'],
                ];
            @endphp
            <div class="modal-panel" wire:key="dialog-{{ $selected->id }}-{{ $pendingAction ?? 'details' }}">
                @if ($pendingAction === null)
                    <div class="modal-head">
                        <x-avatar :name="$name" class="account-avatar" />
                        <div class="min-w-0 flex-1">
                            <h2 id="account-dialog-title" class="text-lg font-semibold break-words" tabindex="-1" autofocus x-init="$el.focus()">{{ $name }}</h2>
                            <p class="text-sm break-all text-fg-muted">{{ $selected->email }}</p>
                        </div>
                        <button type="button" class="topbar-button -mt-1 -mr-2 shrink-0" aria-label="Close" x-on:click="$el.closest('dialog').close()">
                            <x-icon name="x" />
                        </button>
                    </div>

                    <dl class="account-details">
                        <div>
                            <dt>Status</dt>
                            <dd>
                                @switch ($selected->status)
                                    @case (AccountStatus::Active) Active @break
                                    @case (AccountStatus::Suspended) Suspended{{ $selected->statusChangedAt ? ' since '.$date($selected->statusChangedAt) : '' }} @break
                                    @default Deleted{{ $selected->statusChangedAt ? ' on '.$date($selected->statusChangedAt) : '' }}
                                @endswitch
                            </dd>
                        </div>
                        <div>
                            <dt>Roles</dt>
                            <dd>{{ collect($selected->roles)->map(fn ($role) => ucfirst($role->value))->implode(', ') ?: 'None' }}</dd>
                        </div>
                        <div>
                            <dt>Two-factor</dt>
                            <dd>{{ $selected->twoFactorConfirmed ? 'On' : 'Off' }}</dd>
                        </div>
                        <div>
                            <dt>Last active</dt>
                            <dd>{{ $selected->lastActiveAt ? Carbon::parse($selected->lastActiveAt)->diffForHumans() : 'Not signed in' }}</dd>
                        </div>
                        <div>
                            <dt>Joined</dt>
                            <dd>{{ $date($selected->createdAt) }}</dd>
                        </div>
                    </dl>

                    @if ($error)
                        <x-alert tone="danger" class="mx-5">{{ $error }}</x-alert>
                    @endif

                    @if ($available !== [])
                        <div class="border-t border-divider py-2">
                            @foreach ($available as $action)
                                <button type="button" class="menu-item" wire:click="ask('{{ $action }}')">
                                    <x-icon :name="$actions[$action][1]" class="size-4" />{{ $actions[$action][0] }}
                                </button>
                            @endforeach
                        </div>
                    @elseif ($selected->id === $me)
                        <p class="border-t border-divider px-5 py-4 text-sm text-fg-muted">
                            This is your account. Another admin can change it here; your own two-factor settings are under
                            <a href="{{ route('two-factor.setup') }}" class="font-medium text-fg underline">Security</a>.
                        </p>
                    @else
                        <p class="border-t border-divider px-5 py-4 text-sm text-fg-muted">
                            Deletion was requested for this account. Its data is removed by the erasure job, so it can’t be changed.
                        </p>
                    @endif
                @else
                    @php [$heading, $body, $variant] = $confirmations[$pendingAction]; @endphp
                    <div class="space-y-3 px-5 pt-5">
                        <h2 id="account-dialog-title" class="text-lg font-semibold break-words" tabindex="-1" autofocus x-init="$el.focus()">{{ $heading }}</h2>
                        <p class="text-fg-muted">{{ $body }}</p>
                        @if ($error)
                            <x-alert tone="danger">{{ $error }}</x-alert>
                        @endif
                    </div>
                    <div class="modal-actions">
                        <x-button wire:click="back">Back</x-button>
                        <x-button :variant="$variant" :icon="$actions[$pendingAction][1]" wire:click="confirm" wire:loading.attr="aria-busy" wire:target="confirm" busy-label="Working…">
                            {{ $actions[$pendingAction][0] }}
                        </x-button>
                    </div>
                @endif
            </div>
        @endif
    </dialog>
</div>
