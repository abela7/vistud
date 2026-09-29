<?php

namespace App\Livewire\Study;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\NoteDetails;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The pinned notes' button in the bottom corner of every student page: one
 * pin opens its note, several open a short list (the owner's review,
 * 2026-09-29). The note opens in a window of its own beside the page
 * (resources/js/note-window.js), so what's being studied stays in view. The
 * note whose own page this is has no button; it's already open.
 */
final class PinnedNotes extends Component
{
    use Notices;

    /** The ID of the note this page shows, if it's a note page. */
    #[Locked]
    public ?string $current = null;

    private Notes $notes;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    public function boot(Notes $notes, Workspaces $workspaces, PrincipalFactory $principals): void
    {
        $this->notes = $notes;
        $this->workspaces = $workspaces;
        $this->principals = $principals;
    }

    public function unpin(string $id): void
    {
        try {
            $note = $this->notes->unpin($this->principal(), $id);
            $this->notify("“{$note->displayTitle()}” is unpinned.", 'info');
        } catch (NotFound) {
            // Gone since the list was drawn: the refresh below shows what's left.
        }
        $this->dispatch('pins-changed');
    }

    /** A note was pinned or unpinned elsewhere on the page (its actions, a list). */
    #[On('pins-changed')]
    public function refresh(): void {}

    public function render(): View
    {
        $by = $this->principal();
        $pinned = array_values(array_filter(
            $this->notes->pinned($by),
            fn (NoteDetails $note) => $note->id !== $this->current,
        ));

        return view('livewire.study.pinned-notes', [
            'pinned' => $pinned,
            'names' => count($pinned) > 1 ? $this->workspaceNames($by) : [],
        ]);
    }

    /** @return array<string, string> workspace names by ID, archived ones too: a pin outlives its workspace's archiving */
    private function workspaceNames(Principal $by): array
    {
        $names = [];
        foreach ([...$this->workspaces->list($by), ...$this->workspaces->list($by, archived: true)] as $workspace) {
            $names[$workspace->id] = $workspace->name;
        }

        return $names;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
