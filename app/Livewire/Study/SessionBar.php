<?php

namespace App\Livewire\Study;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The open study session in the top bar, on every student page
 * (docs/specs/study-memory.md §4): its clock, a pause or resume button, and
 * a link to the session. Its heartbeat tells the server the page is in use.
 * Nothing shows when no session is open.
 */
final class SessionBar extends Component
{
    private Sessions $sessions;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    public function boot(Sessions $sessions, Workspaces $workspaces, PrincipalFactory $principals): void
    {
        $this->sessions = $sessions;
        $this->workspaces = $workspaces;
        $this->principals = $principals;
    }

    /** The page is in use (resources/js/session.js). */
    public function heartbeat(): void
    {
        $session = $this->sessions->current($this->principal());
        if ($session !== null) {
            $this->sessions->touch($this->principal(), $session->id);
        }
    }

    public function pause(): void
    {
        $this->act(fn (SessionDetails $s) => $this->sessions->pause($this->principal(), $s->id));
    }

    public function resume(): void
    {
        $this->act(fn (SessionDetails $s) => $this->sessions->resume($this->principal(), $s->id));
    }

    #[On('session-changed')]
    public function refresh(): void {}

    public function render(): View
    {
        $session = $this->sessions->current($this->principal());

        return view('livewire.study.session-bar', [
            'session' => $session,
            'workspace' => $session === null ? null : $this->workspaces->find($this->principal(), $session->workspaceId),
        ]);
    }

    private function act(callable $action): void
    {
        $session = $this->sessions->current($this->principal());
        if ($session === null) {
            return;
        }
        try {
            $action($session);
        } catch (Conflict|NotFound) {
            // Changed in another tab: the refresh below shows where it is now.
        }
        $this->dispatch('session-changed');
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
