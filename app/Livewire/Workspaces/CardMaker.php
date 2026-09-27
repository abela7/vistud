<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\CardMaker as Maker;
use App\Study\Notes;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Making flashcards with any AI (docs/specs/study-memory.md §4.5): choose a
 * topic, how many and a note to make them from; copy the prompt into an AI;
 * paste its reply back; keep the cards you want. Opened with the
 * `card-maker-open` event (optionally with a topic).
 */
final class CardMaker extends Component
{
    #[Locked]
    public string $workspaceId;

    /** prompt or review, or null when the dialog is closed. */
    #[Locked]
    public ?string $step = null;

    #[Locked]
    public ?string $notice = null;

    /** @var list<string> the cards that couldn't be saved, and why */
    #[Locked]
    public array $problems = [];

    public string $topicId = '';

    public int $count = 10;

    public string $noteId = '';

    public string $text = '';

    /** @var list<array<string, mixed>> as App\Study\CardMaker::read() gives them */
    public array $cards = [];

    private Maker $maker;

    private Topics $topics;

    private Notes $notes;

    private PrincipalFactory $principals;

    public function boot(Maker $maker, Topics $topics, Notes $notes, PrincipalFactory $principals): void
    {
        $this->maker = $maker;
        $this->topics = $topics;
        $this->notes = $notes;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    #[On('card-maker-open')]
    public function open(?string $topicId = null): void
    {
        $this->close();
        [$this->step, $this->topicId] = ['prompt', $topicId ?? ''];
        $this->reset('notice', 'problems');
        $this->dispatch('card-maker-dialog-open');
    }

    /** Finds the cards in the pasted reply. */
    public function read(): void
    {
        $this->resetErrorBag();
        if (trim($this->text) === '') {
            $this->addError('text', 'Paste the AI\'s reply first.');

            return;
        }
        if (mb_strlen($this->text) > 200_000) {
            $this->addError('text', 'That\'s too long to read at once. Paste it in two parts.');

            return;
        }
        $this->cards = $this->maker->read($this->principal(), $this->workspaceId, $this->text, $this->topicId === '' ? null : $this->topicId);
        if ($this->cards === []) {
            $this->addError('text', 'No cards found. Paste the AI\'s reply as it is: each card looks like <flashcard topic="…"><front>…</front><back>…</back></flashcard>.');

            return;
        }
        $this->step = 'review';
    }

    public function back(): void
    {
        $this->step = 'prompt';
        $this->cards = [];
    }

    public function save(): void
    {
        if ($this->step !== 'review') {
            $this->read();

            return;
        }
        $result = $this->maker->save($this->principal(), $this->workspaceId, $this->cards);
        $n = $result['saved'];
        $this->notice = $n === 0 ? 'No cards were added.' : ($n === 1 ? 'Added 1 card.' : "Added {$n} cards.").' They\'re due for their first review now.';
        $this->problems = array_map(fn ($failed) => Str::limit((string) ($this->cards[$failed[0]]['front'] ?? ''), 60).': '.$failed[1], $result['failed']);

        $this->dispatch('flashcards-changed');
        $this->dispatch('card-maker-dialog-close');
        $this->close();
    }

    public function close(): void
    {
        $this->reset('step', 'text', 'cards', 'noteId', 'count');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $by = $this->principal();
        $prompt = null;
        if ($this->step === 'prompt') {
            try {
                $prompt = $this->maker->prompt($by, $this->workspaceId, $this->topicId === '' ? null : $this->topicId, $this->count, $this->noteId === '' ? null : $this->noteId);
            } catch (NotFound) {
                [$this->topicId, $this->noteId] = ['', ''];
                $prompt = $this->maker->prompt($by, $this->workspaceId, null, $this->count);
            }
        }

        return view('livewire.workspaces.card-maker', [
            'prompt' => $prompt,
            'topics' => $this->step === null ? [] : $this->topics->list($by, $this->workspaceId),
            'notes' => $this->step === 'prompt' ? $this->notes->list($by, $this->workspaceId) : [],
            'ticked' => count(array_filter($this->cards, fn ($card) => ($card['include'] ?? false) && ! ($card['known'] ?? false))),
        ]);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
