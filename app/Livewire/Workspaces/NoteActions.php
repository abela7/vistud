<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Livewire\Study\PinnedNotes;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\Gone;
use App\Study\Notes;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** The note page's actions: pin the note, move it to the trash, or restore it. The note's ID is locked. */
final class NoteActions extends Component
{
    use Notices;

    #[Locked]
    public string $noteId;

    private Notes $notes;

    private PrincipalFactory $principals;

    public function boot(Notes $notes, PrincipalFactory $principals): void
    {
        $this->notes = $notes;
        $this->principals = $principals;
    }

    /** Pinned, the note has a button in the corner of every page (App\Livewire\Study\PinnedNotes). */
    public function togglePin(): void
    {
        $by = $this->principals->fromRequest(request());

        try {
            $note = $this->notes->find($by, $this->noteId);
            if ($note->isPinned()) {
                $this->notes->unpin($by, $note->id);
                $this->notify('Unpinned.', 'info');
            } else {
                $this->notes->pin($by, $note->id);
                $this->notify('Pinned. Its button is now in the corner of every page.');
            }
        } catch (Conflict|Gone $problem) {
            $this->notify($problem->getMessage(), 'info');
        }

        $this->dispatch('pins-changed')->to(PinnedNotes::class);
    }

    public function trash(): void
    {
        $by = $this->principals->fromRequest(request());
        $note = $this->notes->find($by, $this->noteId);
        $this->notes->trash($by, $note->id);

        session()->flash('workspace-notice', "“{$note->displayTitle()}” is in the trash. You can restore it there for ".Notes::TRASH_DAYS.' days.');
        $this->redirectRoute('workspaces.show', [$note->workspaceId, 'notes'], navigate: true);
    }

    public function restore(): void
    {
        $note = $this->notes->restore($this->principals->fromRequest(request()), $this->noteId);

        $this->redirectRoute('workspaces.notes.show', [$note->workspaceId, $note->id], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.workspaces.note-actions', [
            'note' => $this->notes->find($this->principals->fromRequest(request()), $this->noteId),
        ]);
    }
}
