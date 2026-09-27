<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Flashcards;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Writing a flashcard by hand, changing one, or deleting it
 * (docs/specs/study-memory.md §4.5). One dialog, opened from anywhere on the
 * page with the `flashcard-new` (optionally with a topic), `flashcard-edit`
 * or `flashcard-delete` events; it says `flashcards-changed` when it saves.
 */
final class FlashcardEditor extends Component
{
    #[Locked]
    public string $workspaceId;

    /** new, edit or delete, or null when the dialog is closed. */
    #[Locked]
    public ?string $mode = null;

    #[Locked]
    public ?string $cardId = null;

    /** How many cards were added since the dialog opened, for "Save and add another". */
    #[Locked]
    public int $added = 0;

    public string $front = '';

    public string $back = '';

    public string $topicId = '';

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

    #[On('flashcard-new')]
    public function create(?string $topicId = null): void
    {
        $this->close();
        $this->mode = 'new';
        $this->topicId = $topicId ?? '';
        $this->dispatch('flashcard-dialog-open');
    }

    #[On('flashcard-edit')]
    public function edit(string $id): void
    {
        $card = $this->card($id);
        if ($card === null) {
            return;
        }
        $this->close();
        [$this->mode, $this->cardId, $this->front, $this->back, $this->topicId] = ['edit', $card->id, $card->front, $card->back, $card->topicId ?? ''];
        $this->dispatch('flashcard-dialog-open');
    }

    #[On('flashcard-delete')]
    public function confirmDelete(string $id): void
    {
        $card = $this->card($id);
        if ($card === null) {
            return;
        }
        $this->close();
        [$this->mode, $this->cardId, $this->front] = ['delete', $card->id, $card->front];
        $this->dispatch('flashcard-dialog-open');
    }

    /** Saves; with $another, clears the card and stays open for the next one. */
    public function save(bool $another = false): void
    {
        $this->resetErrorBag();
        $by = $this->principal();
        $topicId = $this->topicId === '' ? null : $this->topicId;
        try {
            match ($this->mode) {
                'new' => $this->flashcards->add($by, $this->workspaceId, $topicId, $this->front, $this->back),
                'edit' => $this->flashcards->update($by, (string) $this->cardId, $topicId, $this->front, $this->back),
                'delete' => $this->flashcards->retire($by, (string) $this->cardId),
                default => null,
            };
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        } catch (NotFound) {
            $this->addError('front', $this->mode === 'new' ? 'That topic no longer exists. Choose another.' : 'This card no longer exists.');

            return;
        }

        $this->dispatch('flashcards-changed');
        if ($another && $this->mode === 'new') {
            $this->added++;
            $this->reset('front', 'back');
            $this->dispatch('flashcard-front-focus');

            return;
        }
        $this->dispatch('flashcard-dialog-close');
        $this->close();
    }

    public function close(): void
    {
        $this->reset('mode', 'cardId', 'front', 'back', 'topicId', 'added');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.workspaces.flashcard-editor', [
            'topics' => $this->mode === null || $this->mode === 'delete' ? [] : $this->topics->list($this->principal(), $this->workspaceId),
        ]);
    }

    private function card(string $id): ?object
    {
        try {
            $card = $this->flashcards->find($this->principal(), $id);
        } catch (NotFound) {
            return null;
        }

        return $card->workspaceId === $this->workspaceId ? $card : null;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
