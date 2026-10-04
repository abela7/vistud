<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\FlashcardDetails;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A round of flashcard review (docs/specs/study-memory.md §4.5): one card at
 * a time, the student turns it over and says how it went. The first answer
 * to a card in a round moves it on the ladder; a card missed comes back once
 * at the end of the round, for another go that is recorded but moves
 * nothing. Practising early moves nothing either.
 */
final class FlashcardReview extends Component
{
    #[Locked]
    public string $workspaceId;

    /** A topic's id, '' for cards without one, or null for all. */
    #[Locked]
    public ?string $topicId = null;

    /** A module's id, '' for cards in none, or null for all. */
    #[Locked]
    public ?string $moduleId = null;

    #[Locked]
    public bool $early = false;

    /** @var list<string> the round's card ids, in order; missed cards are added again at the end */
    #[Locked]
    public array $queue = [];

    #[Locked]
    public int $position = 0;

    /** @var array<string, string> card id => the first answer this round */
    #[Locked]
    public array $firsts = [];

    #[Locked]
    public int $rounds = 1;

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

    public function mount(string $workspaceId, ?string $topicId = null, bool $early = false, ?string $moduleId = null): void
    {
        [$this->workspaceId, $this->topicId, $this->early, $this->moduleId] = [$workspaceId, $topicId, $early, $moduleId];
        $this->queue = $this->flashcards->queue($this->principal(), $workspaceId, $topicId, $early, $moduleId);
    }

    /** How the current card went: correct, partial or incorrect. */
    public function answer(string $result): void
    {
        $id = $this->queue[$this->position] ?? null;
        if ($id === null || ! in_array($result, Flashcards::RESULTS, true)) {
            return;
        }
        $first = ! isset($this->firsts[$id]);
        try {
            $this->flashcards->answer($this->principal(), $id, $result, schedule: $first && ! $this->early);
        } catch (NotFound) {
            // Deleted meanwhile: move on.
        }
        if ($first) {
            $this->firsts[$id] = $result;
            if ($result === 'incorrect') {
                $this->queue[] = $id;
            }
        }
        $this->position++;
    }

    /** Another round: what's due now (or, practising, the next cards not due yet). */
    public function again(bool $early = false): void
    {
        $this->early = $early;
        $this->queue = $this->flashcards->queue($this->principal(), $this->workspaceId, $this->topicId, $early, $this->moduleId);
        [$this->position, $this->firsts] = [0, []];
        $this->rounds++;
    }

    public function editCard(): void
    {
        if (($id = $this->queue[$this->position] ?? null) !== null) {
            $this->dispatch('flashcard-edit', id: $id);
        }
    }

    #[On('flashcards-changed')]
    public function refreshCard(): void
    {
        // Drawing again shows the card as edited.
    }

    public function render(): View
    {
        $by = $this->principal();
        $card = null;
        while ($card === null && isset($this->queue[$this->position])) {
            try {
                $card = $this->flashcards->find($by, $this->queue[$this->position]);
            } catch (NotFound) {
                $this->position++;
            }
        }
        $retry = $card !== null && isset($this->firsts[$card->id]);
        $topicName = null;
        if ($card?->topicId !== null || ($this->topicId !== null && $this->topicId !== '')) {
            $names = collect($this->topics->list($by, $this->workspaceId))->pluck('name', 'id');
            $topicName = $names[$card?->topicId ?? $this->topicId] ?? null;
        }

        return view('livewire.workspaces.flashcard-review', [
            'card' => $card,
            'retry' => $retry,
            'hints' => $card === null ? [] : $this->hints($card, $retry),
            'cardTopic' => $card?->topicId !== null ? $topicName : null,
            'roundModule' => $this->moduleId === null ? null : ($this->moduleId === '' ? 'No module' : (collect($this->modules->list($by, $this->workspaceId))->firstWhere('id', $this->moduleId)?->title)),
            'roundTopic' => $this->topicId === null ? null : ($this->topicId === '' ? 'cards without a topic' : $topicName),
            'total' => count($this->queue),
            'tally' => array_count_values($this->firsts) + ['correct' => 0, 'partial' => 0, 'incorrect' => 0],
            'counts' => $card === null ? $this->flashcards->counts($by, $this->workspaceId, $this->topicId, $this->moduleId) : null,
            'today' => $this->flashcards->today($by),
        ]);
    }

    /** @return array<string, string> result => when the card comes back, in words */
    private function hints(FlashcardDetails $card, bool $retry): array
    {
        if ($this->early || $retry) {
            return [];
        }
        $hints = [];
        foreach (Flashcards::RESULTS as $result) {
            $hints[$result] = Flashcards::gapWords(Flashcards::next($card->step, $result)[1]);
        }

        return $hints;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
