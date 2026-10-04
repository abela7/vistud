<?php

namespace App\Livewire\Workspaces;

use App\Appearance\Theme;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\Unprocessable;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The course dialog: editing, archiving and restoring a course (from its page or its card on My courses). A new course
 * is made on its own page (App\Livewire\Workspaces\CourseNew). A thin adapter over
 * App\Study\Workspaces, which checks everything; the workspace ID is locked.
 */
final class Form extends Component
{
    #[Locked]
    public ?string $workspaceId = null;

    public string $name = '';

    public string $colour = 'blue';

    public string $icon = 'book-open';

    public string $code = '';

    public string $term = '';

    public string $startsOn = '';

    public string $endsOn = '';

    /** Labels, a team, priorities, milestones and how an assignment is going (off unless the student wants them). */
    public bool $projectTools = false;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    public function boot(Workspaces $workspaces, PrincipalFactory $principals): void
    {
        $this->workspaces = $workspaces;
        $this->principals = $principals;
    }

    public function mount(?string $workspaceId = null): void
    {
        $this->workspaceId = $workspaceId;
        $this->loadFields();
    }

    public function save(): mixed
    {
        if ($this->workspaceId === null) {
            return null;
        }
        $input = [
            'name' => $this->name,
            'colour' => $this->colour,
            'icon' => $this->icon,
            'code' => $this->code,
            'term' => $this->term,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
            'project_tools' => $this->projectTools,
        ];

        try {
            $workspace = $this->workspaces->update($this->principal(), $this->workspaceId, $input);
        } catch (Unprocessable $e) {
            $this->resetErrorBag();
            $names = ['starts_on' => 'startsOn', 'ends_on' => 'endsOn'];
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($names[$field] ?? $field, $messages[0]);
            }

            return null;
        }

        return $this->redirectRoute('workspaces.show', $workspace->id, navigate: true);
    }

    public function archive(): mixed
    {
        if ($this->workspaceId === null) {
            return null;
        }
        $this->workspaces->archive($this->principal(), $this->workspaceId);
        session()->flash('workspace-notice', "{$this->name} is archived. You'll find it under Archived, where you can restore it.");

        return $this->redirectRoute('home', navigate: true);
    }

    public function delete(): mixed
    {
        if ($this->workspaceId === null) {
            return null;
        }
        $name = $this->name;
        $this->workspaces->delete($this->principal(), $this->workspaceId);
        session()->flash('workspace-notice', "{$name} was deleted.");

        return $this->redirectRoute('home', navigate: true);
    }

    #[On('workspace-edit')]
    public function openForEdit(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        $this->resetErrorBag();
        $this->loadFields();
        $this->dispatch('workspace-form-open');
    }

    #[On('workspace-restore')]
    public function restore(): mixed
    {
        if ($this->workspaceId === null) {
            return null;
        }
        $this->workspaces->restore($this->principal(), $this->workspaceId);
        session()->flash('workspace-notice', "{$this->name} is back in your courses.");

        return $this->redirectRoute('workspaces.show', $this->workspaceId, navigate: true);
    }

    /** The dialog closed without saving: back to the stored details, or an empty form. */
    public function cancel(): void
    {
        $this->resetErrorBag();
        $this->loadFields();
    }

    public function render(): View
    {
        return view('livewire.workspaces.form', [
            'colours' => Theme::CATEGORIES,
            'icons' => Workspaces::ICONS,
            'archived' => $this->workspaceId !== null && $this->workspaces->find($this->principal(), $this->workspaceId)->archived(),
        ]);
    }

    private function loadFields(): void
    {
        if ($this->workspaceId === null) {
            $this->reset('name', 'colour', 'icon', 'code', 'term', 'startsOn', 'endsOn', 'projectTools');

            return;
        }

        $workspace = $this->workspaces->find($this->principal(), $this->workspaceId);
        $this->name = $workspace->name;
        $this->colour = $workspace->colour;
        $this->icon = $workspace->icon;
        $this->code = (string) $workspace->code;
        $this->term = (string) $workspace->term;
        $this->startsOn = (string) $workspace->startsOn;
        $this->endsOn = (string) $workspace->endsOn;
        $this->projectTools = $workspace->projectTools;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
