<?php

declare(strict_types=1);

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\ClassSessions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A module's classes: the list, and the form that adds one. Recording
 * happens on the class's own page.
 */
final class ModuleClasses extends Component
{
    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $moduleId;

    public bool $adding = false;

    public string $title = '';

    public string $kind = 'lecture';

    public string $heldOn = '';

    public string $teacher = '';

    private ClassSessions $classes;

    private PrincipalFactory $principals;

    public function boot(ClassSessions $classes, PrincipalFactory $principals): void
    {
        $this->classes = $classes;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId, string $moduleId): void
    {
        $this->workspaceId = $workspaceId;
        $this->moduleId = $moduleId;
    }

    public function start(): void
    {
        $this->resetErrorBag();
        $this->adding = true;
        if ($this->heldOn === '') {
            $this->heldOn = $this->classes->today($this->principal());
        }
    }

    public function cancel(): void
    {
        $this->resetErrorBag();
        $this->adding = false;
        $this->title = '';
        $this->teacher = '';
        $this->kind = 'lecture';
        $this->heldOn = '';
    }

    public function add(): void
    {
        $this->resetErrorBag();
        try {
            $class = $this->classes->create($this->principal(), $this->workspaceId, $this->moduleId, [
                'title' => $this->title,
                'kind' => $this->kind,
                'held_on' => $this->heldOn,
                'teacher' => $this->teacher,
            ]);
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError((string) $field, is_array($messages) ? (string) ($messages[0] ?? '') : (string) $messages);
            }

            return;
        } catch (NotFound) {
            $this->addError('title', 'This module could not be found.');

            return;
        }

        $this->redirect(route('workspaces.modules.classes.show', [$this->workspaceId, $this->moduleId, $class->id]), navigate: true);
    }

    public function render(): View
    {
        $by = $this->principal();

        return view('livewire.workspaces.module-classes', [
            'classes' => $this->classes->forModule($by, $this->workspaceId, $this->moduleId),
            'kinds' => ClassSessions::KINDS,
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
