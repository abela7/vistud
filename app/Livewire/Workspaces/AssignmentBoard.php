<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\Files;
use App\Study\Modules;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A workspace's assignments, its Assignments section (the owner's review, 2026-10-02): what's still to do as
 * cards, the soonest deadline first, each with how long is left and how many files it has; what's done on its
 * own tab. A card opens the assignment's own page (App\Livewire\Workspaces\AssignmentPage).
 */
final class AssignmentBoard extends Component
{
    #[Locked]
    public string $workspaceId;

    /** open or done. */
    public string $filter = 'open';

    private Activities $activities;

    private Modules $modules;

    private Files $files;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    public function boot(Activities $activities, Modules $modules, Files $files, Workspaces $workspaces, PrincipalFactory $principals): void
    {
        [$this->activities, $this->modules, $this->files, $this->workspaces, $this->principals] = [$activities, $modules, $files, $workspaces, $principals];
    }

    public function show(string $filter): void
    {
        $this->filter = $filter === 'done' ? 'done' : 'open';
    }

    public function render(): View
    {
        $by = $this->principal();
        $all = $this->activities->list($by, $this->workspaceId);
        $open = array_values(array_filter($all, fn ($a) => $a->status !== 'done'));
        $done = array_values(array_filter($all, fn ($a) => $a->status === 'done'));
        $filesIn = [];
        foreach ($this->files->list($by, $this->workspaceId) as $file) {
            if ($file->folderId !== null) {
                $filesIn[$file->folderId] = ($filesIn[$file->folderId] ?? 0) + 1;
            }
        }

        return view('livewire.workspaces.assignment-board', [
            'workspace' => $this->workspaces->find($by, $this->workspaceId),
            'shown' => $this->filter === 'done' ? $done : $open,
            'counts' => ['open' => count($open), 'done' => count($done)],
            'moduleTitles' => collect($this->modules->list($by, $this->workspaceId))->pluck('title', 'id')->all(),
            'filesIn' => $filesIn,
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
