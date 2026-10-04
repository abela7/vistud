<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Findings;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One topic on a sheet (docs/specs/vistud-2-blueprint.md §3.5.3): how the student says they stand on it (covered, understood,
 * still confusing), its cards, open questions and key points, and the sessions on it; and to rename it, move it to another
 * module or study it. Opened by the `topic-sheet-open` event from a topic's row (the module page; later Progress). A thin
 * adapter over App\Study\Topics and the services that know the rest; the topic's id is locked.
 */
final class TopicSheet extends Component
{
    #[Locked]
    public string $workspaceId;

    #[Locked]
    public ?string $topicId = null;

    public string $name = '';

    public string $moduleId = '';

    #[Locked]
    public ?string $notice = null;

    private Topics $topics;

    private Modules $modules;

    private Flashcards $flashcards;

    private Questions $questions;

    private Findings $findings;

    private Sessions $sessions;

    private PrincipalFactory $principals;

    public function boot(Topics $topics, Modules $modules, Flashcards $flashcards, Questions $questions, Findings $findings, Sessions $sessions, PrincipalFactory $principals): void
    {
        $this->topics = $topics;
        $this->modules = $modules;
        $this->flashcards = $flashcards;
        $this->questions = $questions;
        $this->findings = $findings;
        $this->sessions = $sessions;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    #[On('topic-sheet-open')]
    public function open(string $topicId): void
    {
        $topic = $this->topics->find($this->principal(), $topicId);
        $topic->workspaceId === $this->workspaceId || throw new NotFound;
        $this->topicId = $topic->id;
        $this->name = $topic->name;
        $this->moduleId = (string) $topic->moduleId;
        $this->notice = null;
        $this->resetErrorBag();
        $this->dispatch('topic-sheet-dialog-open');
    }

    /** How the student says they stand on it. */
    public function report(string $status): void
    {
        $this->resetErrorBag();
        try {
            $this->topics->report($this->principal(), (string) $this->topicId, $status);
        } catch (Unprocessable $e) {
            $this->addError('status', $e->getMessage());

            return;
        }
        $this->notice = 'Saved.';
        $this->dispatch('topics-changed');
    }

    public function rename(): void
    {
        $this->resetErrorBag();
        try {
            $this->topics->rename($this->principal(), (string) $this->topicId, $this->name);
        } catch (Unprocessable $e) {
            $this->addError('name', array_values($e->details['fields'] ?? [])[0][0] ?? $e->getMessage());

            return;
        }
        $this->notice = 'Renamed.';
        $this->dispatch('topics-changed');
    }

    public function move(): void
    {
        $this->resetErrorBag();
        try {
            $this->topics->move($this->principal(), (string) $this->topicId, $this->moduleId === '' ? null : $this->moduleId);
        } catch (NotFound|Unprocessable) {
            $this->addError('moduleId', 'That module no longer exists. Choose another.');

            return;
        }
        $this->dispatch('topics-changed');
        $this->dispatch('topic-sheet-dialog-close');
        $this->topicId = null;
    }

    /** Study it now, with no dialog. */
    public function study(): void
    {
        $topic = $this->topics->find($this->principal(), (string) $this->topicId);
        $this->dispatch('study-next', moduleId: $topic->moduleId, topicId: $topic->id);
        $this->dispatch('topic-sheet-dialog-close');
    }

    public function close(): void
    {
        $this->topicId = null;
        $this->notice = null;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $by = $this->principal();
        $data = ['topic' => null, 'modules' => [], 'cards' => ['total' => 0, 'due' => 0], 'open' => [], 'points' => [], 'sessionCount' => 0];
        if ($this->topicId !== null) {
            try {
                $topic = $this->topics->find($by, $this->topicId);
                $data = [
                    'topic' => $topic,
                    'modules' => $this->modules->list($by, $this->workspaceId),
                    'cards' => $this->flashcards->counts($by, $this->workspaceId, $topic->id),
                    'open' => array_values(array_filter($this->questions->list($by, $this->workspaceId), fn ($q) => $q->topicId === $topic->id && $q->status !== 'answered')),
                    'points' => $this->findings->byTopic($by, $this->workspaceId)[$topic->id] ?? [],
                    'sessionCount' => $topic->moduleId === null ? 0 : count(array_filter($this->sessions->forModule($by, $this->workspaceId, $topic->moduleId), fn ($s) => $s->topicId === $topic->id)),
                ];
            } catch (NotFound) {
                $this->topicId = null;
            }
        }

        return view('livewire.workspaces.topic-sheet', $data);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
