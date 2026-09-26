<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\Unprocessable;
use App\Study\Instructions as InstructionTexts;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The instructions a study session starts from, on a workspace's Overview
 * (docs/specs/study-memory.md §3): about the student, for every course, and
 * for this course. A module's own are in its menu in Modules.
 */
final class Instructions extends Component
{
    #[Locked]
    public string $workspaceId;

    /** me or workspace: which one the dialog edits, or null when it's closed. */
    #[Locked]
    public ?string $editing = null;

    #[Locked]
    public ?string $notice = null;

    public string $text = '';

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

    public function edit(string $which): void
    {
        $this->close();
        $this->editing = $which === 'me' ? 'me' : 'workspace';
        $this->text = $this->instructions->get($this->principal(), $this->scope());
        $this->dispatch('instructions-dialog-open');
    }

    public function save(): void
    {
        $this->resetErrorBag();
        try {
            $this->instructions->set($this->principal(), $this->scope(), $this->text);
        } catch (Unprocessable $e) {
            $this->addError('text', $e->details['fields']['text'][0] ?? $e->getMessage());

            return;
        }
        $this->notice = $this->editing === 'me' ? 'What the assistant knows about you is saved.' : 'The instructions for this course are saved.';
        $this->close();
        $this->dispatch('instructions-dialog-close');
    }

    public function close(): void
    {
        $this->reset('editing', 'text');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $by = $this->principal();

        return view('livewire.workspaces.instructions', [
            'me' => $this->instructions->get($by, 'me'),
            'course' => $this->instructions->get($by, "workspace:{$this->workspaceId}"),
        ]);
    }

    private function scope(): string
    {
        return $this->editing === 'me' ? 'me' : "workspace:{$this->workspaceId}";
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
