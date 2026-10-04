<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\BulkActions;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A workspace's Flashcards section (docs/specs/study-memory.md §4.5): what's
 * due today, the cards by module (each with what's due and a review of its
 * own), a module's cards by topic with when each is next due, and the ways to
 * add cards (by hand, or with an AI). Writing and deleting happen in
 * App\Livewire\Workspaces\FlashcardEditor; making cards with an AI in
 * App\Livewire\Workspaces\CardMaker; reviewing on its own page.
 */
final class Deck extends Component
{
    use BulkActions, Notices;

    #[Locked]
    public string $workspaceId;

    /** A module's id, 'none' for cards in none, or '' for all (the modules, not the cards). */
    #[Url(as: 'module', except: '')]
    public string $module = '';

    /** A topic's id, 'none' for cards without one, or '' for all. */
    #[Url(as: 'topic', except: '')]
    public string $topic = '';

    private Flashcards $flashcards;

    private Topics $topics;

    private Modules $modules;

    private PrincipalFactory $principals;

    public function boot(Flashcards $flashcards, Topics $topics, Modules $modules, PrincipalFactory $principals): void
    {
        $this->flashcards = $flashcards;
        $this->topics = $topics;
        $this->modules = $modules;
        $this->principals = $principals;
    }

    /** Another module: a topic of another module no longer fits. */
    public function updatedModule(): void
    {
        $this->topic = '';
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
        $modules = $this->modules->list($by, $this->workspaceId);
        if ($this->module !== '' && $this->module !== 'none' && ! collect($modules)->contains('id', $this->module)) {
            $this->module = '';
        }
        $moduleFilter = match ($this->module) {
            '' => null,
            'none' => '',
            default => $this->module,
        };
        $topics = $this->topics->list($by, $this->workspaceId);
        if ($this->topic !== '' && $this->topic !== 'none' && ! collect($topics)->contains('id', $this->topic)) {
            $this->topic = '';
        }
        $topicFilter = match ($this->topic) {
            '' => null,
            'none' => '',
            default => $this->topic,
        };
        $all = $this->flashcards->counts($by, $this->workspaceId);
        // All modules and no topic chosen: the modules, not every card (unless the cards are all in one, or in none).
        $showCards = $moduleFilter !== null || $topicFilter !== null || count($all['modules']) <= 1;
        $cards = $showCards ? $this->flashcards->list($by, $this->workspaceId, $topicFilter, $moduleFilter) : [];
        $byTopic = [];
        foreach ($cards as $card) {
            $byTopic[$card->topicId ?? ''][] = $card;
        }

        $here = $moduleFilter === null ? $all : $this->flashcards->counts($by, $this->workspaceId, null, $moduleFilter);

        return view('livewire.workspaces.deck', [
            'modules' => $modules,
            'topics' => $topics,
            // The topics to choose from: those with cards here, and the one chosen.
            'topicChoices' => array_values(array_filter($topics, fn ($t) => isset($here['topics'][$t->id]) || $t->id === $this->topic)),
            'showCards' => $showCards,
            'cards' => $cards,
            'byTopic' => $byTopic,
            'all' => $all,
            'here' => $here,
            'counts' => $this->flashcards->counts($by, $this->workspaceId, $topicFilter, $moduleFilter),
            'today' => $this->flashcards->today($by),
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
