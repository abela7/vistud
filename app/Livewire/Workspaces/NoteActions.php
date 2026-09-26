<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Study\Notes;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The note page's actions: move the note to the trash, or restore it. The note's ID is locked. */
final class NoteActions extends Component
{
    #[Locked]
    public string $noteId;

    private Notes $notes;

    private PrincipalFactory $principals;

    public function boot(Notes $notes, PrincipalFactory $principals): void
    {
        $this->notes = $notes;
        $this->principals = $principals;
    }

    public function trash(): void
    {
        $by = $this->principals->fromRequest(request());
        $note = $this->notes->find($by, $this->noteId);
        $this->notes->trash($by, $note->id);

        session()->flash('workspace-notice', "“{$note->displayTitle()}” is in the trash. You can restore it there for ".Notes::TRASH_DAYS.' days.');
        $this->redirectRoute('workspaces.show', [$note->workspaceId, 'notes']);
    }

    public function restore(): void
    {
        $note = $this->notes->restore($this->principals->fromRequest(request()), $this->noteId);

        $this->redirectRoute('workspaces.notes.show', [$note->workspaceId, $note->id]);
    }

    public function render(): View
    {
        return view('livewire.workspaces.note-actions', [
            'note' => $this->notes->find($this->principals->fromRequest(request()), $this->noteId),
        ]);
    }
}
