<?php

namespace App\Livewire\Admin\Accounts;

use App\Identity\AccountDetails;
use App\Identity\Accounts;
use App\Identity\AccountStatus;
use App\Identity\PrincipalFactory;
use App\Identity\Roles;
use App\Identity\TwoFactorReset;
use App\Platform\Access\Principal;
use App\Platform\Access\Role;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\PasswordConfirmationRequired;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The admin Accounts page (WP6; ADR 0003 §10.1–10.3). A thin adapter: every
 * read and every action is one service call, and the services check the
 * permissions, the recent password confirmation and the last-admin rule.
 *
 * Managing an account is two steps: open() picks the account, ask() picks
 * the action, and confirm() runs it. The account ID is a locked property,
 * so the browser can't change which account a confirmed action targets.
 */
final class Index extends Component
{
    public const ACTIONS = ['suspend', 'reactivate', 'grant-admin', 'revoke-admin', 'reset-two-factor'];

    private const PAGE_SIZE = 50;

    private const MAX_PAGES = 20;

    /** How many pages of accounts are shown ("Show more"). */
    public int $pages = 1;

    /** Part of a name or an email address. */
    public string $search = '';

    #[Locked]
    public ?string $selectedId = null;

    #[Locked]
    public ?string $pendingAction = null;

    #[Locked]
    public ?string $notice = null;

    #[Locked]
    public ?string $error = null;

    private Accounts $accounts;

    private Roles $roles;

    private TwoFactorReset $twoFactor;

    private PrincipalFactory $principals;

    public function boot(Accounts $accounts, Roles $roles, TwoFactorReset $twoFactor, PrincipalFactory $principals): void
    {
        $this->accounts = $accounts;
        $this->roles = $roles;
        $this->twoFactor = $twoFactor;
        $this->principals = $principals;
    }

    public function open(string $userId): void
    {
        try {
            $this->accounts->details($this->principal(), $userId);
        } catch (NotFound) {
            $this->notice = 'That account no longer exists.';

            return;
        }

        $this->selectedId = $userId;
        $this->pendingAction = null;
        $this->error = null;
        $this->dispatch('account-dialog-open');
    }

    public function ask(string $action): void
    {
        if ($this->selectedId !== null && in_array($action, self::ACTIONS, true)) {
            $this->pendingAction = $action;
            $this->error = null;
        }
    }

    public function back(): void
    {
        $this->pendingAction = null;
        $this->error = null;
    }

    public function dismiss(): void
    {
        $this->selectedId = null;
        $this->pendingAction = null;
        $this->error = null;
    }

    public function confirm(): mixed
    {
        if ($this->selectedId === null || $this->pendingAction === null) {
            return null;
        }

        $by = $this->principal();
        $id = $this->selectedId;

        try {
            $name = $this->accounts->details($by, $id)->name;
            match ($this->pendingAction) {
                'suspend' => $this->accounts->suspend($by, $id),
                'reactivate' => $this->accounts->reactivate($by, $id),
                'grant-admin' => $this->roles->grantAdmin($by, $id),
                'revoke-admin' => $this->roles->revokeAdmin($by, $id),
                'reset-two-factor' => $this->twoFactor->reset($by, $id),
            };
        } catch (PasswordConfirmationRequired) {
            // Protected actions need a password confirmed in the last ten
            // minutes. Confirm it, then come back to this page.
            redirect()->setIntendedUrl(route('admin.accounts'));

            return $this->redirectRoute('password.confirm');
        } catch (NotFound) {
            $this->error = 'That account no longer exists.';

            return null;
        } catch (Conflict $e) {
            $this->error = match ($e->errorCode) {
                'last_admin' => 'This would leave no active admin. Make someone else an admin first.',
                'target_deleted' => 'That account has been deleted, so it can’t be changed.',
                default => 'That change clashes with the account’s current state. Reload the page and try again.',
            };

            return null;
        }

        $this->notice = match ($this->pendingAction) {
            'suspend' => "{$name} is suspended and has been logged out everywhere.",
            'reactivate' => "{$name} can log in again.",
            'grant-admin' => "{$name} is now an admin.",
            'revoke-admin' => "{$name} is no longer an admin.",
            'reset-two-factor' => "{$name}’s two-factor authentication is reset. They’ve been logged out everywhere.",
        };
        $this->dismiss();
        $this->dispatch('account-dialog-close');

        return null;
    }

    public function updatedSearch(): void
    {
        $this->pages = 1;
    }

    public function showMore(): void
    {
        $this->pages = min($this->pages + 1, self::MAX_PAGES);
    }

    public function render(): View
    {
        $by = $this->principal();

        $accounts = [];
        $cursor = null;
        $pages = max(1, min($this->pages, self::MAX_PAGES));
        for ($page = 0; $page < $pages; $page++) {
            $result = $this->accounts->list($by, $cursor, self::PAGE_SIZE, $this->search);
            array_push($accounts, ...$result['data']);
            $cursor = $result['next_cursor'];
            if ($cursor === null) {
                break;
            }
        }

        $selected = null;
        if ($this->selectedId !== null) {
            try {
                $selected = $this->accounts->details($by, $this->selectedId);
            } catch (NotFound) {
                $this->dismiss();
            }
        }

        return view('livewire.admin.accounts.index', [
            'accounts' => $accounts,
            'hasMore' => $cursor !== null && $pages < self::MAX_PAGES,
            'selected' => $selected,
            'me' => $by->userId,
            'available' => $selected === null ? [] : self::availableActions($selected, $by),
        ]);
    }

    /**
     * The actions offered for an account. The services decide what is
     * allowed; this only hides what makes no sense, and your own account,
     * which you manage from Security.
     *
     * @return list<string>
     */
    public static function availableActions(AccountDetails $account, Principal $by): array
    {
        if ($account->id === $by->userId || $account->status === AccountStatus::Deleted) {
            return [];
        }

        return array_values(array_filter([
            $account->status === AccountStatus::Active ? 'suspend' : 'reactivate',
            in_array(Role::Admin, $account->roles, true) ? 'revoke-admin' : 'grant-admin',
            $account->twoFactorConfirmed ? 'reset-two-factor' : null,
        ]));
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
