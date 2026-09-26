<?php

namespace App\Livewire\Admin\Accounts;

use App\Identity\Invitations as InvitationService;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\PasswordConfirmationRequired;
use App\Platform\Errors\Unprocessable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Inviting people, on the admin Accounts page (WP6; ADR 0003 D4, PM
 * decision Q3). An admin enters an email address and gets a single-use,
 * expiring link to share themselves. The link is shown in the response
 * that creates it and nowhere else: it is never kept in the component's
 * state, so it never travels back to the server. It carries the token
 * after "#", which browsers never send, so it stays out of server logs.
 */
final class Invitations extends Component
{
    public string $email = '';

    /** The new invitation's address, once one is issued. Never its token. */
    #[Locked]
    public ?string $issuedTo = null;

    #[Locked]
    public ?string $cancelling = null;

    #[Locked]
    public ?string $notice = null;

    /** The link, for the one render that shows it. */
    private ?string $link = null;

    private InvitationService $invitations;

    private PrincipalFactory $principals;

    public function boot(InvitationService $invitations, PrincipalFactory $principals): void
    {
        $this->invitations = $invitations;
        $this->principals = $principals;
    }

    public function invite(): mixed
    {
        $this->resetErrorBag();

        try {
            $issued = $this->invitations->invite($this->principal(), $this->email);
        } catch (PasswordConfirmationRequired) {
            return $this->confirmPasswordFirst();
        } catch (Unprocessable) {
            $this->addError('email', 'Enter an email address, like name@example.com.');

            return null;
        } catch (Conflict) {
            $this->addError('email', 'Someone already has an account with this email address.');

            return null;
        }

        $this->issuedTo = mb_strtolower(trim($this->email));
        $this->email = '';
        $this->link = route('invitations.show').'#'.$issued->token;

        return null;
    }

    /** The invite dialog closed: start the next one afresh. */
    public function done(): void
    {
        $this->email = '';
        $this->issuedTo = null;
        $this->resetErrorBag();
    }

    public function askCancel(string $invitationId): void
    {
        $this->cancelling = $invitationId;
        $this->dispatch('invitation-cancel-open');
    }

    public function keep(): void
    {
        $this->cancelling = null;
    }

    public function cancel(): mixed
    {
        if ($this->cancelling === null) {
            return null;
        }

        $by = $this->principal();
        $email = collect($this->invitations->pending($by))->firstWhere('id', $this->cancelling)?->email;

        try {
            $this->invitations->revoke($by, $this->cancelling);
        } catch (PasswordConfirmationRequired) {
            return $this->confirmPasswordFirst();
        } catch (NotFound) {
            // Already gone: nothing left to cancel.
        }

        $this->notice = $email === null ? 'The invitation is cancelled.' : "The invitation for {$email} is cancelled. Its link no longer works.";
        $this->cancelling = null;
        $this->dispatch('invitation-cancel-close');

        return null;
    }

    public function render(): View
    {
        $pending = $this->invitations->pending($this->principal());

        return view('livewire.admin.accounts.invitations', [
            'pending' => $pending,
            'link' => $this->link,
            'cancellingEmail' => $this->cancelling === null ? null : collect($pending)->firstWhere('id', $this->cancelling)?->email,
            'ttlHours' => (int) config('vistud.invitations.ttl_hours', 72),
        ]);
    }

    private function confirmPasswordFirst(): mixed
    {
        // Inviting and cancelling need a password confirmed in the last ten
        // minutes. Confirm it, then come back to this page.
        redirect()->setIntendedUrl(route('admin.accounts'));

        return $this->redirectRoute('password.confirm');
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
