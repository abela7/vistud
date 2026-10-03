<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Plans;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * An assignment on a page of its own (the owner's review, 2026-10-02), in one calm column: a summary (how long is
 * left, where the student is with it: to do, in progress, done), its details (name, kind, deadline and module)
 * folded behind Edit, its plan, its files, and deleting it at the bottom. Also the page for a new one: Create makes it, with its
 * own folder, and the page becomes its page, where any files chosen before go up into that folder. The
 * assignment's ID is locked; the service checks everything.
 */
final class AssignmentPage extends Component
{
    use Notices;

    #[Locked]
    public string $workspaceId;

    /** The assignment this page is for; null until a new one is created. */
    #[Locked]
    public ?string $activityId = null;

    public string $title = '';

    public string $kind = 'assignment';

    public string $dueOn = '';

    public string $dueTime = '';

    public string $moduleId = '';

    public string $status = 'todo';

    private Activities $activities;

    private Modules $modules;

    private Files $files;

    private Notes $notes;

    private Folders $folders;

    private Plans $plans;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    public function boot(Activities $activities, Modules $modules, Files $files, Notes $notes, Folders $folders, Plans $plans, Workspaces $workspaces, PrincipalFactory $principals): void
    {
        [$this->activities, $this->modules, $this->files, $this->notes, $this->folders, $this->plans, $this->workspaces, $this->principals] =
            [$activities, $modules, $files, $notes, $folders, $plans, $workspaces, $principals];
    }

    /** A new one starts in `$inModule` when it is a module of this workspace. (Not named like a property: Livewire would assign it.) */
    public function mount(string $workspaceId, ?string $activityId = null, ?string $inModule = null): void
    {
        [$this->workspaceId, $this->activityId] = [$workspaceId, $activityId];
        if ($activityId === null) {
            $this->moduleId = collect($this->modules->list($this->principal(), $workspaceId))->firstWhere('id', $inModule)?->id ?? '';

            return;
        }
        $this->showDetails($this->assignment());
    }

    /** Creates it, or saves its details. True when it was just created: the page becomes its page, and the files chosen before go up (resources/js/uploader.js). */
    public function save(): bool
    {
        $this->resetErrorBag();
        $by = $this->principal();
        $input = ['title' => $this->title, 'kind' => $this->kind, 'due_on' => $this->dueOn, 'due_time' => $this->dueTime, 'module_id' => $this->moduleId];
        try {
            if ($this->activityId !== null) {
                $this->showDetails($this->activities->update($by, $this->activityId, $input));
                $this->notify('Saved.');
                $this->dispatch('details-saved');

                return false;
            }
            $made = $this->activities->create($by, $this->workspaceId, $input);
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError(['due_on' => 'dueOn', 'due_time' => 'dueTime', 'module_id' => 'moduleId'][$field] ?? $field, $messages[0]);
            }

            return false;
        } catch (NotFound) {
            $this->addError('title', 'That no longer exists. Go back and try again.');

            return false;
        }

        $this->activityId = $made->id;
        $this->showDetails($made);
        $this->notify("“{$made->title}” is added.");
        $this->dispatch('details-saved');
        $this->js('history.replaceState(history.state, "", '.json_encode(route('workspaces.assignments.show', [$this->workspaceId, $made->id])).')');

        return true;
    }

    /** The plan was ticked (App\Livewire\Workspaces\AssignmentPlan): the first tick starts the assignment, the last may finish it. */
    #[On('plan-changed')]
    public function planChanged(): void
    {
        if ($this->activityId !== null) {
            $this->status = $this->assignment()->status;
        }
    }

    /** To do, in progress or done, saved as soon as it is picked. */
    public function updatedStatus(string $status): void
    {
        if ($this->activityId === null) {
            return;
        }
        try {
            $this->activities->setStatus($this->principal(), $this->activityId, $status);
            $this->notify(['todo' => 'Back to to do.', 'doing' => 'In progress.', 'done' => 'Done. Well done!'][$status] ?? 'Saved.');
        } catch (Unprocessable) {
            $this->status = $this->assignment()->status;
        }
    }

    /** The place for its files: its folder, made now if it has none (resources/js/uploader.js). @return array{0: string, 1: string} */
    public function filesFolder(): array
    {
        return ['folder', $this->activities->folder($this->principal(), (string) $this->activityId)->id];
    }

    /** Called by resources/js/uploader.js once a round of files has gone up. */
    public function uploadsFinished(int $uploaded, int $refused): void
    {
        if ($uploaded > 0) {
            $this->notify(($uploaded === 1 ? '1 file' : "{$uploaded} files").' added.');
        }
    }

    public function delete(): void
    {
        if ($this->activityId === null) {
            return;
        }
        $by = $this->principal();
        try {
            $assignment = $this->assignment();
            $folder = $this->folderOf($assignment);
            $this->activities->delete($by, $assignment->id);
            session()->flash('workspace-notice', $folder === null
                ? "“{$assignment->title}” is deleted."
                : "“{$assignment->title}” is deleted. Its folder “{$folder->name}” and its files stay where they were.");
        } catch (NotFound) {
            // Already gone: the same end.
        }
        $this->redirectRoute('workspaces.show', [$this->workspaceId, 'assignments'], navigate: true);
    }

    public function render(): View
    {
        $by = $this->principal();
        $workspace = $this->workspaces->find($by, $this->workspaceId);
        $assignment = $this->activityId === null ? null : $this->assignment();
        $folder = $assignment === null ? null : $this->folderOf($assignment);
        $plan = $assignment === null ? null : $this->plans->get($by, $assignment->id);
        $progress = $plan?->progress();

        return view('livewire.workspaces.assignment-page', [
            'progress' => $progress,
            'pace' => $assignment === null ? null : $progress->pace($assignment),
            'health' => $assignment === null ? null : $plan->health($assignment),
            'workspace' => $workspace,
            'assignment' => $assignment,
            'modules' => $this->modules->list($by, $this->workspaceId),
            'folder' => $folder,
            'files' => $folder === null ? [] : array_values(array_filter($this->files->list($by, $this->workspaceId), fn ($file) => $file->folderId === $folder->id)),
            'notes' => $folder === null ? [] : array_values(array_filter($this->notes->list($by, $this->workspaceId), fn ($note) => $note->folderId === $folder->id)),
            'maxUpload' => Files::maxBytes(),
        ]);
    }

    private function showDetails(ActivityDetails $assignment): void
    {
        [$this->title, $this->kind, $this->dueOn, $this->dueTime, $this->moduleId, $this->status] =
            [$assignment->title, $assignment->kind, (string) $assignment->dueOn, (string) $assignment->dueTime, (string) $assignment->moduleId, $assignment->status];
    }

    private function assignment(): ActivityDetails
    {
        $assignment = $this->activities->find($this->principal(), (string) $this->activityId);
        $assignment->workspaceId === $this->workspaceId || throw new NotFound;

        return $assignment;
    }

    private function folderOf(ActivityDetails $assignment): ?object
    {
        if ($assignment->folderId === null) {
            return null;
        }
        try {
            return $this->folders->find($this->principal(), $assignment->folderId);
        } catch (NotFound) {
            return null;
        }
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
