<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\Unprocessable;
use App\Study\Instructions as InstructionTexts;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The instructions a study session starts from (docs/specs/study-memory.md
 * §3), in one dialog opened with the `instructions-open` event: about the
 * student, for every course, and for this course. A module's own are in its
 * menu.
 */
final class Instructions extends Component
{
    #[Locked]
    public string $workspaceId;

    #[Locked]
    public bool $editing = false;

    #[Locked]
    public ?string $notice = null;

    public string $me = '';

    public string $course = '';

    private InstructionTexts $instructions;

    private PrincipalFactory $principals;

    public function boot(InstructionTexts $instructions, PrincipalFactory $principals): void
    {
        $this->instructions = $instructions;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    #[On('instructions-open')]
    public function edit(): void
    {
        $this->close();
        $by = $this->principal();
        [$this->editing, $this->me, $this->course] = [true, $this->instructions->get($by, 'me'), $this->instructions->get($by, "workspace:{$this->workspaceId}")];
        $this->dispatch('instructions-dialog-open');
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $by = $this->principal();
        foreach (['me' => 'me', 'course' => "workspace:{$this->workspaceId}"] as $field => $scope) {
            try {
                $this->instructions->set($by, $scope, $this->{$field});
            } catch (Unprocessable $e) {
                $this->addError($field, $e->details['fields']['text'][0] ?? $e->getMessage());
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $this->notice = 'Instructions saved.';
        $this->close();
        $this->dispatch('instructions-dialog-close');
    }

    public function close(): void
    {
        $this->reset('editing', 'me', 'course');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.workspaces.instructions');
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
