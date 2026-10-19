<?php

declare(strict_types=1);

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\ClassSessions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A module's classes: the list, and one tap on a kind to add today's. Recording
 * happens on the class's own page.
 */
final class ModuleClasses extends Component
{
    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $moduleId;

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

    /**
     * One tap on a kind makes today's class (the day from the student's own clock) and opens it, ready to record.
     * It is named by its kind and day: "Lecture · 19 Oct 2026".
     */
    public function add(string $kind): void
    {
        $by = $this->principal();
        if (! isset(ClassSessions::KINDS[$kind])) {
            return;
        }
        $day = $this->classes->today($by);
        try {
            $class = $this->classes->create($by, $this->workspaceId, $this->moduleId, [
                'title' => ClassSessions::KINDS[$kind].' · '.Carbon::parse($day)->format('j M Y'),
                'kind' => $kind,
                'held_on' => $day,
            ]);
        } catch (NotFound|Unprocessable) {
            $this->addError('kind', 'This module could not be found.');

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
