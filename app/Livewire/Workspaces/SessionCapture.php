<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\WriteBack;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Save from the chat (docs/specs/study-memory.md §4.4): the student pastes
 * the tutor's replies, reviews what the tutor marked, and saves what they
 * keep. Items are the student's own to edit; the service checks each one as
 * it saves it.
 */
final class SessionCapture extends Component
{
    use Notices;

    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $sessionId;

    /** paste or review, or null when the dialog is closed. */
    #[Locked]
    public ?string $step = null;

    /** @var list<string> the items that couldn't be saved, and why */
    #[Locked]
    public array $problems = [];

    public string $text = '';

    /** @var list<array<string, mixed>> as App\Study\WriteBack::review() gives them */
    public array $items = [];

    public bool $note = true;

    private WriteBack $writeBack;

    private Topics $topics;

    private Sessions $sessions;

    private PrincipalFactory $principals;

    public function boot(WriteBack $writeBack, Topics $topics, Sessions $sessions, PrincipalFactory $principals): void
    {
        $this->writeBack = $writeBack;
        $this->topics = $topics;
        $this->sessions = $sessions;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId, string $sessionId): void
    {
        [$this->workspaceId, $this->sessionId] = [$workspaceId, $sessionId];
    }

    /** Opens to paste; given the text (the built-in chat's replies), straight to the review of its marks. */
    #[On('capture-open')]
    public function open(?string $text = null): void
    {
        $this->close();
        $this->step = 'paste';
        if (is_string($text) && trim($text) !== '') {
            $this->text = $text;
            $this->read();
        }
        $this->dispatch('capture-dialog-open');
    }

    /** Finds the marks in the pasted text. */
    public function read(): void
    {
        $this->resetErrorBag();
        if (trim($this->text) === '') {
            $this->addError('text', 'Paste the tutor\'s replies first.');

            return;
        }
        if (mb_strlen($this->text) > 500_000) {
            $this->addError('text', 'That\'s too long to read at once. Paste it in two parts.');

            return;
        }
        $this->items = $this->writeBack->review($this->principal(), $this->sessionId, $this->text);
        if ($this->items === []) {
            $this->addError('text', 'Nothing to save was found. Paste the tutor\'s replies as they are, tags included.');

            return;
        }
        $this->step = 'review';
    }

    public function back(): void
    {
        $this->step = 'paste';
        $this->items = [];
    }

    public function save(): void
    {
        if ($this->step !== 'review') {
            $this->read();

            return;
        }
        $result = $this->writeBack->apply($this->principal(), $this->sessionId, $this->items, $this->note);

        $words = [
            'finding' => ['key point', 'key points'], 'question' => ['question', 'questions'], 'flashcard' => ['flashcard', 'flashcards'],
            'attempt' => ['answer', 'answers'], 'status' => ['status', 'statuses'],
        ];
        $parts = [];
        foreach ($words as $kind => [$one, $many]) {
            if (($n = $result['saved'][$kind] ?? 0) > 0) {
                $parts[] = $n.' '.($n === 1 ? $one : $many);
            }
        }
        if (isset($result['saved']['summary'])) {
            $parts[] = 'the summary';
        }
        if (isset($result['saved']['checkpoint'])) {
            $parts[] = 'where the session stands';
        }
        $this->notice = $parts === [] ? 'Nothing was saved.' : 'Saved '.Str::of(implode(', ', $parts))->replaceLast(', ', ' and ').'.';
        $this->problems = array_map(fn ($failed) => $this->label($this->items[$failed[0]] ?? []).': '.$failed[1], $result['failed']);

        $this->dispatch('session-changed');
        $this->dispatch('capture-dialog-close');
        $this->reset('step', 'text', 'items', 'note');
    }

    public function close(): void
    {
        $this->reset('step', 'text', 'items', 'note');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $by = $this->principal();
        $session = $this->sessions->find($by, $this->sessionId);

        return view('livewire.workspaces.session-capture', [
            'topics' => $this->step === 'review' ? $this->topics->list($by, $session->workspaceId) : [],
            'ticked' => count(array_filter($this->items, fn ($item) => ($item['include'] ?? false) && ! ($item['saved'] ?? false))),
        ]);
    }

    /** A short name for an item, for saying which one failed. */
    private function label(array $item): string
    {
        $text = $item['text'] ?? $item['front'] ?? $item['asked'] ?? $item['topic_name'] ?? '';

        return Str::limit((string) $text, 60);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
