<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\BulkActions;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Flashcards;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A workspace's Flashcards section (docs/specs/study-memory.md §4.5): what's
 * due today, every card by topic with when it's next due, and the ways to
 * add cards (by hand, or with an AI). Writing and deleting happen in
 * App\Livewire\Workspaces\FlashcardEditor; making cards with an AI in
 * App\Livewire\Workspaces\CardMaker; reviewing on its own page.
 */
final class Deck extends Component
{
    use BulkActions, Notices;

    #[Locked]
    public string $workspaceId;

    /** A topic's id, 'none' for cards without one, or '' for all. */
    #[Url(as: 'topic', except: '')]
    public string $topic = '';

    private Flashcards $flashcards;

    private Topics $topics;

    private PrincipalFactory $principals;

    public function boot(Flashcards $flashcards, Topics $topics, PrincipalFactory $principals): void
    {
        $this->flashcards = $flashcards;
        $this->topics = $topics;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    #[On('flashcards-changed')]
    public function refreshCards(): void
    {
        // Drawing again is enough.
    }

    public function editCard(string $id): void
    {
        $this->dispatch('flashcard-edit', id: $id);
    }

    public function deleteCard(string $id): void
    {
        $this->dispatch('flashcard-delete', id: $id);
    }

    public function openBulkRemove(array $keys): void
    {
        $this->bulkKeys = $keys;
        $this->dispatch('bulk-flashcard-dialog-open');
    }

    public function confirmBulkRemove(): void
    {
        $this->bulk('remove', $this->bulkKeys);
        $this->bulkKeys = [];
        $this->dispatch('bulk-flashcard-dialog-close');
        $this->dispatch('selection-clear');
    }

    protected function allowedBulkTypes(): array
    {
        return ['flashcard'];
    }

    protected function performBulkAction(string $action, array $items, array $payload): void
    {
        $by = $this->principal();
        $done = 0;

        if (in_array($action, ['remove', 'delete'], true)) {
            foreach ($items as [$type, $id]) {
                try {
                    $this->flashcards->retire($by, $id);
                    $done++;
                } catch (NotFound) {
                    // Ignored
                }
            }
            $this->notify($done === 1 ? '1 card removed.' : "{$done} cards removed.");
            $this->dispatch('flashcards-changed');
            $this->dispatch('selection-clear');

            return;
        }
    }

    public function render(): View
    {
        $by = $this->principal();
        $topics = $this->topics->list($by, $this->workspaceId);
        if ($this->topic !== '' && $this->topic !== 'none' && ! collect($topics)->contains('id', $this->topic)) {
            $this->topic = '';
        }
        $filter = match ($this->topic) {
            '' => null,
            'none' => '',
            default => $this->topic,
        };
        $cards = $this->flashcards->list($by, $this->workspaceId, $filter);
        $byTopic = [];
        foreach ($cards as $card) {
            $byTopic[$card->topicId ?? ''][] = $card;
        }

        return view('livewire.workspaces.deck', [
            'topics' => $topics,
            'cards' => $cards,
            'byTopic' => $byTopic,
            'all' => $this->flashcards->counts($by, $this->workspaceId),
            'counts' => $this->flashcards->counts($by, $this->workspaceId, $filter),
            'today' => $this->flashcards->today($by),
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
