<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** My courses: the student's workspaces as cards, and the archived ones to restore. */
final class Index extends Component
{
    use Notices;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    public function boot(Workspaces $workspaces, PrincipalFactory $principals): void
    {
        $this->workspaces = $workspaces;
        $this->principals = $principals;
    }

    /** The workspace the delete dialog asks about; only confirmDelete() sets it. */
    #[Locked]
    public ?string $deletingId = null;

    #[Locked]
    public ?string $deletingName = null;

    public function restore(string $workspaceId): void
    {
        $by = $this->principals->fromRequest(request());
        $this->workspaces->restore($by, $workspaceId);
        $this->notice = $this->workspaces->find($by, $workspaceId)->name.' is back in your courses.';
    }

    public function archive(string $workspaceId): void
    {
        $by = $this->principals->fromRequest(request());
        $workspace = $this->workspaces->find($by, $workspaceId);
        $this->workspaces->archive($by, $workspaceId);
        $this->notice = "{$workspace->name} is archived. You'll find it under Archived, where you can restore it.";
    }

    public function confirmDelete(string $workspaceId): void
    {
        $by = $this->principals->fromRequest(request());
        $workspace = $this->workspaces->find($by, $workspaceId);
        $this->deletingId = $workspaceId;
        $this->deletingName = $workspace->name;
        $this->dispatch('workspace-delete-dialog-open');
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
        $this->deletingName = null;
    }

    public function delete(): void
    {
        if ($this->deletingId === null) {
            return;
        }
        $by = $this->principals->fromRequest(request());
        $name = $this->deletingName ?? 'The course';
        $this->workspaces->delete($by, $this->deletingId);
        $this->deletingId = null;
        $this->deletingName = null;
        $this->notice = "{$name} was deleted.";
        $this->dispatch('workspace-delete-dialog-close');
    }

    public function render(): View
    {
        $by = $this->principals->fromRequest(request());

        return view('livewire.workspaces.index', [
            'active' => $this->workspaces->list($by),
            'archived' => $this->workspaces->list($by, archived: true),
            'counts' => $this->workspaces->counts($by),
        ]);
    }
}
