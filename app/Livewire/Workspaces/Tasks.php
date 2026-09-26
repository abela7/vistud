<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\Modules;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A workspace's assignments and tasks, on its Overview
 * (docs/specs/study-memory.md §3): what's still to do, soonest due first,
 * and what's done. The service checks everything; the IDs the dialog acts on
 * are locked.
 */
final class Tasks extends Component
{
    #[Locked]
    public string $workspaceId;

    /** task (new or edit), delete, or null when the dialog is closed. */
    #[Locked]
    public ?string $mode = null;

    #[Locked]
    public ?string $targetId = null;

    #[Locked]
    public bool $showDone = false;

    #[Locked]
    public ?string $notice = null;

    public string $kind = 'assignment';

    public string $title = '';

    public string $dueOn = '';

    public string $moduleId = '';

    private Activities $activities;

    private Modules $modules;

    private PrincipalFactory $principals;

    public function boot(Activities $activities, Modules $modules, PrincipalFactory $principals): void
    {
        $this->activities = $activities;
        $this->modules = $modules;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    public function newTask(): void
    {
        $this->open('task');
    }

    public function editTask(string $id): void
    {
        $task = $this->activities->find($this->principal(), $id);
        $this->open('task', $task->id);
        [$this->kind, $this->title, $this->dueOn, $this->moduleId] = [$task->kind, $task->title, (string) $task->dueOn, (string) $task->moduleId];
    }

    public function confirmDelete(string $id): void
    {
        $task = $this->activities->find($this->principal(), $id);
        $this->open('delete', $task->id);
    }

    /** To do, doing or done. */
    public function setStatus(string $id, string $status): void
    {
        $this->activities->setStatus($this->principal(), $id, $status);
        $this->notice = null;
    }

    public function toggleDone(): void
    {
        $this->showDone = ! $this->showDone;
    }

    public function save(): void
    {
        $by = $this->principal();
        $this->resetErrorBag();
        $input = ['kind' => $this->kind, 'title' => $this->title, 'due_on' => $this->dueOn, 'module_id' => $this->moduleId];

        try {
            $this->notice = match (true) {
                $this->mode === 'task' && $this->targetId === null => $this->activities->create($by, $this->workspaceId, $input)->title.' is added.',
                $this->mode === 'task' => $this->activities->update($by, $this->targetId, $input)->title.' is saved.',
                $this->mode === 'delete' => $this->deleted($by),
                default => null,
            };
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError(['due_on' => 'dueOn', 'module_id' => 'moduleId'][$field] ?? $field, $messages[0]);
            }

            return;
        } catch (NotFound) {
            $this->addError('title', 'That no longer exists. Close this and try again.');

            return;
        }

        $this->close();
        $this->dispatch('tasks-dialog-close');
    }

    public function close(): void
    {
        $this->reset('mode', 'targetId', 'kind', 'title', 'dueOn', 'moduleId');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $by = $this->principal();
        $tasks = $this->activities->list($by, $this->workspaceId);
        $modules = $this->modules->list($by, $this->workspaceId);
        $moduleTitles = [];
        foreach ($modules as $module) {
            $moduleTitles[$module->id] = $module->title;
        }

        return view('livewire.workspaces.tasks', [
            'open' => array_values(array_filter($tasks, fn ($t) => $t->status !== 'done')),
            'done' => array_values(array_filter($tasks, fn ($t) => $t->status === 'done')),
            'modules' => $modules,
            'moduleTitles' => $moduleTitles,
            'target' => $this->targetId === null ? null : (collect($tasks)->firstWhere('id', $this->targetId)?->title),
        ]);
    }

    private function open(string $mode, ?string $targetId = null): void
    {
        $this->close();
        [$this->mode, $this->targetId] = [$mode, $targetId];
        $this->dispatch('tasks-dialog-open');
    }

    private function deleted(Principal $by): string
    {
        $title = $this->activities->find($by, (string) $this->targetId)->title;
        $this->activities->delete($by, (string) $this->targetId);

        return "{$title} is deleted.";
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
