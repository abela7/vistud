<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\QuestionDetails;
use App\Study\Questions;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The questions a student doesn't get yet (docs/specs/study-memory.md §3):
 * write one down in a line, see them by status (pending, stuck, answered),
 * and open one in the side panel to change its words or status and write
 * the answer. On a module's page it holds the module's questions; in a
 * study session, the session's module's (or, with none, the session's);
 * in Progress, all of them. `question-new` (optionally with a topic) opens
 * the panel for a new one. Quiet, it has no line and shows nothing until
 * there is a question: the page has its own button for a new one.
 */
final class QuestionBoard extends Component
{
    use Notices;

    #[Locked]
    public string $workspaceId;

    /** The module whose questions these are; new ones go there too. */
    #[Locked]
    public ?string $moduleId = null;

    /** The study session new questions are asked in (and, with no module, whose questions show). */
    #[Locked]
    public ?string $sessionId = null;

    /** A heading level that fits the page. */
    #[Locked]
    public int $level = 2;

    /** No line to write one down, and nothing shown until there is a question. */
    #[Locked]
    public bool $quiet = false;

    /** The question open in the panel, 'new' for a new one, or null when it's closed. */
    #[Locked]
    public ?string $editing = null;

    public string $filter = 'all';

    /** The one-line question at the top. */
    public string $text = '';

    public string $question = '';

    public string $status = 'pending';

    public string $answer = '';

    public string $topicId = '';

    public bool $askTeacher = false;

    private Questions $questions;

    private Topics $topics;

    private PrincipalFactory $principals;

    public function boot(Questions $questions, Topics $topics, PrincipalFactory $principals): void
    {
        $this->questions = $questions;
        $this->topics = $topics;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId, ?string $moduleId = null, ?string $sessionId = null, int $level = 2, bool $quiet = false): void
    {
        [$this->workspaceId, $this->moduleId, $this->sessionId, $this->level, $this->quiet] = [$workspaceId, $moduleId, $sessionId, in_array($level, [2, 3], true) ? $level : 2, $quiet];
    }

    /** Writes the one-line question down, pending. */
    public function add(): void
    {
        $this->resetErrorBag();
        try {
            $this->questions->ask($this->principal(), $this->workspaceId, $this->text, null, $this->moduleId, $this->sessionId);
        } catch (Unprocessable $e) {
            $this->addError('text', $e->details['fields']['text'][0] ?? $e->getMessage());

            return;
        }
        $this->reset('text');
        $this->filter = in_array($this->filter, ['all', 'pending'], true) ? $this->filter : 'all';
        $this->dispatch('questions-changed');
    }

    public function show(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'pending', 'stuck', 'answered'], true) ? $filter : 'all';
    }

    #[On('question-new')]
    public function create(?string $topicId = null): void
    {
        $this->close();
        [$this->editing, $this->topicId] = ['new', $topicId ?? ''];
        $this->dispatch('question-dialog-open');
    }

    /** Opens a question in the panel; with $as, already moved to that status (answering it, say). */
    public function open(string $id, ?string $as = null): void
    {
        $question = $this->find($id);
        if ($question === null) {
            return;
        }
        $this->close();
        [$this->editing, $this->question, $this->status, $this->answer, $this->topicId, $this->askTeacher] =
            [$question->id, $question->text, isset(Questions::STATUSES[$as]) ? $as : $question->status, (string) $question->answer, $question->topicId ?? '', $question->askTeacher];
        $this->dispatch('question-dialog-open');
    }

    /** Changes a question's status from its row, in one click. */
    public function mark(string $id, string $status): void
    {
        if ($this->find($id) === null) {
            return;
        }
        $this->questions->setStatus($this->principal(), $id, $status);
        $this->dispatch('questions-changed');
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $by = $this->principal();
        try {
            if ($this->editing === 'new') {
                $asked = $this->questions->ask($by, $this->workspaceId, $this->question, $this->topicId ?: null, $this->moduleId, $this->sessionId);
                $id = $asked->id;
            } else {
                $id = (string) $this->editing;
                if ($this->find($id) === null) {
                    throw new NotFound;
                }
                $this->questions->update($by, $id, $this->question);
            }
            $this->questions->setStatus($by, $id, $this->status, $this->answer);
            $this->questions->setAskTeacher($by, $id, $this->askTeacher);
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError(['text' => 'question'][$field] ?? $field, $messages[0]);
            }

            return;
        } catch (NotFound) {
            $this->addError('question', 'That no longer exists. Close this and try again.');

            return;
        }
        $this->notice = null;
        $this->dispatch('question-dialog-close');
        $this->dispatch('questions-changed');
        $this->close();
    }

    public function delete(): void
    {
        if ($this->editing !== null && $this->editing !== 'new' && $this->find($this->editing) !== null) {
            $this->questions->retire($this->principal(), $this->editing);
            $this->notice = 'The question is deleted.';
            $this->dispatch('questions-changed');
        }
        $this->dispatch('question-dialog-close');
        $this->close();
    }

    public function close(): void
    {
        $this->reset('editing', 'question', 'status', 'answer', 'topicId', 'askTeacher');
        $this->resetErrorBag();
    }

    #[On('questions-changed')]
    public function refreshQuestions(): void
    {
        // Drawing again is enough.
    }

    public function render(): View
    {
        $by = $this->principal();
        $all = $this->list($by);
        $counts = ['all' => count($all), 'pending' => 0, 'stuck' => 0, 'answered' => 0];
        foreach ($all as $question) {
            $counts[$question->status]++;
        }
        // Stuck first, then pending, then answered; newest first within each.
        $order = ['stuck' => 0, 'pending' => 1, 'answered' => 2];
        $shown = array_values(array_filter($all, fn ($q) => $this->filter === 'all' || $q->status === $this->filter));
        usort($shown, fn ($a, $b) => [$order[$a->status], $b->askedAt] <=> [$order[$b->status], $a->askedAt]);
        $topics = $this->topics->list($by, $this->workspaceId);

        return view('livewire.workspaces.question-board', [
            'shown' => $shown,
            'counts' => $counts,
            'topicNames' => collect($topics)->pluck('name', 'id')->all(),
            'topics' => $this->editing === null ? [] : array_values(array_filter($topics, fn ($t) => $this->moduleId === null || $t->moduleId === $this->moduleId || $t->id === $this->topicId)),
        ]);
    }

    /** @return list<QuestionDetails> */
    private function list(Principal $by): array
    {
        return match (true) {
            $this->moduleId !== null => $this->questions->list($by, $this->workspaceId, $this->moduleId),
            $this->sessionId !== null => $this->questions->list($by, $this->workspaceId, null, $this->sessionId),
            default => $this->questions->list($by, $this->workspaceId),
        };
    }

    /** One of this board's questions, or null. */
    private function find(string $id): ?object
    {
        try {
            $question = $this->questions->find($this->principal(), $id);
        } catch (NotFound) {
            return null;
        }

        return $question->workspaceId === $this->workspaceId ? $question : null;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
