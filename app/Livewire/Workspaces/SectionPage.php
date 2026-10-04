<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\EditsPlan;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Activities;
use App\Study\Files;
use App\Study\FileTypes;
use App\Study\Folders;
use App\Study\Notes;
use App\Study\Plans;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A section of an assignment's plan on a page of its own (the owner's review, 2026-10-04): how far it has got and
 * what it is worth, then three tabs. Tasks: its tasks and their sub-tasks (a task is as far as its sub-tasks, the
 * section as far as its tasks). Files: what is kept in its folder (uploaded, or a new folder). Notes: the notes
 * written for it. Everything is kept in the section's own folder, inside the assignment's folder. The tab is in the
 * address. The IDs are locked; the service checks everything.
 */
final class SectionPage extends Component
{
    use EditsPlan, Notices;

    public const TABS = ['tasks' => 'Tasks', 'files' => 'Files', 'notes' => 'Notes'];

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $activityId;

    #[Locked]
    public string $sectionId;

    #[Url]
    public string $tab = 'tasks';

    /** The name typed for a new folder in the section's folder. */
    public string $folderName = '';

    private Plans $plans;

    private Activities $activities;

    private Folders $folders;

    private Files $files;

    private Notes $notes;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    public function boot(Plans $plans, Activities $activities, Folders $folders, Files $files, Notes $notes, Workspaces $workspaces, PrincipalFactory $principals): void
    {
        [$this->plans, $this->activities, $this->folders, $this->files, $this->notes, $this->workspaces, $this->principals] = [$plans, $activities, $folders, $files, $notes, $workspaces, $principals];
    }

    public function mount(string $workspaceId, string $activityId, string $sectionId): void
    {
        [$this->workspaceId, $this->activityId, $this->sectionId] = [$workspaceId, $activityId, $sectionId];
    }

    public function showTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'tasks';
    }

    /** A task of the section; its box is stepText[the section's ID]. */
    public function addTask(): void
    {
        $this->addStep($this->sectionId);
    }

    /** The section's folder, made now if it has none: where its files go up (resources/js/uploader.js). @return array{0: string, 1: string} */
    public function sectionFolder(): array
    {
        return ['folder', $this->plans->folder($this->principal(), $this->sectionId)->id];
    }

    /** Called by resources/js/uploader.js once a round of files has gone up. */
    public function uploadsFinished(int $uploaded, int $refused): void
    {
        if ($uploaded > 0) {
            $this->tab = 'files';
            $this->notify(($uploaded === 1 ? '1 file' : "{$uploaded} files").' added.');
        }
    }

    /** A folder inside the section's folder. */
    public function addFolder(): void
    {
        $made = null;
        if ($this->attempt(['name' => 'folderName'], function () use (&$made) {
            $by = $this->principal();
            $made = $this->folders->create($by, 'folder', $this->plans->folder($by, $this->sectionId)->id, $this->folderName);
        })) {
            $this->reset('folderName');
            $this->notify("Folder “{$made->name}” added.");
            $this->dispatch('folder-added');
        }
    }

    /** A new note in the section's folder, in the editor. */
    public function writeNote(): void
    {
        $folder = null;
        if ($this->attempt([], function () use (&$folder) {
            $folder = $this->plans->folder($this->principal(), $this->sectionId);
        })) {
            $this->redirect(route('workspaces.notes.create', [$this->workspaceId, 'in' => "folder:{$folder->id}"]), navigate: true);
        }
    }

    public function trashFile(string $id): void
    {
        if ($this->attempt([], fn () => $this->files->trash($this->principal(), $id))) {
            $this->notify('Moved to the trash. Restore it from Notes & files.');
        }
    }

    public function trashNote(string $id): void
    {
        if ($this->attempt([], fn () => $this->notes->trash($this->principal(), $id))) {
            $this->notify('Moved to the trash. Restore it from Notes & files.');
        }
    }

    /** Deletes the section and its tasks; its folder stays when something is in it. Back to the assignment. */
    public function deleteSection(): void
    {
        $by = $this->principal();
        try {
            $section = $this->plans->get($by, $this->activityId)->item($this->sectionId);
            $kept = $this->plans->delete($by, $this->sectionId);
            session()->flash('workspace-notice', $kept === null
                ? "The section “{$section?->title}” is deleted."
                : "The section “{$section?->title}” is deleted. Its folder “{$kept}” stays in the assignment's folder, with what is in it.");
        } catch (NotFound) {
            // Already gone: the same end.
        }
        $this->redirectRoute('workspaces.assignments.show', [$this->workspaceId, $this->activityId], navigate: true);
    }

    public function render(): View
    {
        $by = $this->principal();
        $assignment = $this->activities->find($by, $this->activityId);
        $plan = $this->plans->get($by, $this->activityId);
        $section = $plan->item($this->sectionId);
        if ($section === null || $section->kind !== 'part') {
            throw new NotFound;
        }

        [$folders, $files, $notes] = [[], [], []];
        if ($section->folderId !== null) {
            $folders = array_values(array_filter($this->folders->tree($by, $this->workspaceId), fn ($folder) => $folder->parentId === $section->folderId));
            $files = array_values(array_filter($this->files->list($by, $this->workspaceId), fn ($file) => $file->folderId === $section->folderId));
            $notes = array_values(array_filter($this->notes->list($by, $this->workspaceId), fn ($note) => $note->folderId === $section->folderId));
            usort($notes, fn ($a, $b) => $b->updatedAt <=> $a->updatedAt);
        }

        $workspace = $this->workspaces->find($by, $this->workspaceId);

        return view('livewire.workspaces.section-page', [
            'workspace' => $workspace,
            'projectTools' => $workspace->projectTools,
            'assignment' => $assignment,
            'plan' => $plan,
            'section' => $section,
            'standing' => $plan->standing($section),
            'weighted' => $plan->weighted(),
            'tasks' => $plan->steps($section->id),
            'folders' => $folders,
            'files' => $files,
            'notes' => $notes,
            'today' => CarbonImmutable::now($assignment->zone)->toDateString(),
            'maxUpload' => Files::maxBytes(),
            'extensions' => [...array_keys(FileTypes::TYPES), 'jpeg'],
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
