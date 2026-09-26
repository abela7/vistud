<?php

namespace App\Livewire\Journal;

use App\Brain\Store\JournalReader;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Guard;
use App\Platform\Errors\NotFound;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One of the student's own journal entries (WP6; ADR 0003 §10.4, T3). The
 * entry ID is locked, and every render reads through the learner-scoped
 * reader: another learner's entry is exactly as missing as one that doesn't
 * exist (404). Read-only; corrections arrive as new entries (ADR 0002).
 */
final class EntryShow extends Component
{
    #[Locked]
    public string $entryId;

    public function mount(string $entryId): void
    {
        $this->entryId = $entryId;
    }

    public function render(JournalReader $reader, PrincipalFactory $principals): View
    {
        $scope = Guard::learner($principals->fromRequest(request()));
        $stored = $reader->find($scope, $this->entryId) ?? throw new NotFound;

        return view('livewire.journal.entry-show', ['stored' => $stored]);
    }
}
