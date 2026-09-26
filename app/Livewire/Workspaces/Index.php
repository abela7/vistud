<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** My workspaces: the student's workspaces as cards, and the archived ones to restore. */
final class Index extends Component
{
    #[Locked]
    public ?string $notice = null;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    public function boot(Workspaces $workspaces, PrincipalFactory $principals): void
    {
        $this->workspaces = $workspaces;
        $this->principals = $principals;
    }

    public function restore(string $workspaceId): void
    {
        $by = $this->principals->fromRequest(request());
        $this->workspaces->restore($by, $workspaceId);
        $this->notice = $this->workspaces->find($by, $workspaceId)->name.' is back in your workspaces.';
    }

    public function render(): View
    {
        $by = $this->principals->fromRequest(request());

        return view('livewire.workspaces.index', [
            'active' => $this->workspaces->list($by),
            'archived' => $this->workspaces->list($by, archived: true),
        ]);
    }
}
