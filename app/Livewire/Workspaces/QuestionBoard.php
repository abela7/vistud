<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\BulkActions;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Modules;
use App\Study\QuestionDetails;
use App\Study\Questions;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The questions a student doesn't get yet (docs/specs/study-memory.md §3):
 * write one down in a line, see them by status (pending, stuck, answered),
 * and open one on its own page (App\Livewire\Workspaces\QuestionPage) to
 * change its words or status and write the answer. In a study session it
 * holds the session's module's questions (or, with none, the session's); in
 * Progress, all of them. `question-new` (optionally with a topic) goes to
 * the page for a new one. A module's questions have a page of their own
 * (`page`): the heading with Select and New question, a search and a sort
 * as well as the filter, and the questions as cards across the whole width
 * (the owner's review, 2026-09-29). Folded (a study session): no line,
 * nothing until there is a question, then the list closed under its
 * heading until opened.
 */
final class QuestionBoard extends Component
{
    use BulkActions, Notices;

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

    /** The board is the page (a module's questions): the page's own title names it. */
    #[Locked]
    public bool $page = false;

    /** No line to write one down, nothing until there is a question, then the list folded away until opened. */
    #[Locked]
    public bool $folded = false;

    public string $filter = 'all';

    /** status (stuck first), newest or oldest. */
    public string $sort = 'status';

    public string $search = '';

    /** The one-line question at the top. */
    public string $text = '';

    private Questions $questions;

    private Topics $topics;

    private Workspaces $workspaces;

    private Modules $modules;

    private PrincipalFactory $principals;

    public function boot(Questions $questions, Topics $topics, Workspaces $workspaces, Modules $modules, PrincipalFactory $principals): void
    {
        $this->questions = $questions;
        $this->topics = $topics;
        $this->workspaces = $workspaces;
        $this->modules = $modules;
        $this->principals = $principals;
    }

    public const SORTS = ['status' => 'Stuck first', 'newest' => 'Newest first', 'oldest' => 'Oldest first'];

    public function mount(string $workspaceId, ?string $moduleId = null, ?string $sessionId = null, int $level = 2, bool $folded = false, bool $page = false): void
    {
        [$this->workspaceId, $this->moduleId, $this->sessionId, $this->level, $this->folded, $this->page] = [$workspaceId, $moduleId, $sessionId, in_array($level, [2, 3], true) ? $level : 2, $folded, $page];
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

    /** A new question has a page of its own; it comes back to this one when saved. */
    #[On('question-new')]
    public function create(?string $topicId = null): void
    {
        $this->redirectRoute('workspaces.questions.create', array_filter([
            'workspace' => $this->workspaceId,
            'module' => $this->moduleId,
            'topic' => $topicId,
            'from' => $this->pagePath(),
        ]), navigate: true);
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

    public function openBulkRemove(array $keys): void
    {
        $this->bulkKeys = $keys;
        $this->dispatch('bulk-question-dialog-open');
    }

    public function confirmBulkRemove(): void
    {
        $this->bulk('remove', $this->bulkKeys);
        $this->bulkKeys = [];
        $this->dispatch('bulk-question-dialog-close');
        $this->dispatch('selection-clear');
    }

    protected function allowedBulkTypes(): array
    {
        return ['question'];
    }

    protected function performBulkAction(string $action, array $items, array $payload): void
    {
        $by = $this->principal();
        $done = 0;

        if ($action === 'status') {
            $status = (string) ($payload['status'] ?? 'pending');
            foreach ($items as [$type, $id]) {
                try {
                    $this->questions->setStatus($by, $id, $status);
                    $done++;
                } catch (NotFound) {
                    // Ignored
                }
            }
            $word = match ($status) {
                'stuck' => 'Stuck',
                'answered' => 'Answered',
                default => 'Pending',
            };
            $this->notify($done === 1 ? "1 question marked {$word}." : "{$done} questions marked {$word}.");
            $this->dispatch('questions-changed');
            $this->dispatch('selection-clear');

            return;
        }

        if (in_array($action, ['remove', 'delete'], true)) {
            foreach ($items as [$type, $id]) {
                try {
                    $this->questions->retire($by, $id);
                    $done++;
                } catch (NotFound) {
                    // Ignored
                }
            }
            $this->notify($done === 1 ? '1 question removed.' : "{$done} questions removed.");
            $this->dispatch('questions-changed');
            $this->dispatch('selection-clear');

            return;
        }
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
        $search = Str::lower(trim($this->search));
        $shown = array_values(array_filter($all, fn ($q) => ($this->filter === 'all' || $q->status === $this->filter)
            && ($search === '' || Str::contains(Str::lower($q->text.' '.$q->answer), $search))));
        // Stuck first, then pending, then answered, newest first within each; or by when they were asked.
        $order = ['stuck' => 0, 'pending' => 1, 'answered' => 2];
        usort($shown, match ($this->sort) {
            'newest' => fn ($a, $b) => $b->askedAt <=> $a->askedAt,
            'oldest' => fn ($a, $b) => $a->askedAt <=> $b->askedAt,
            default => fn ($a, $b) => [$order[$a->status], $b->askedAt] <=> [$order[$b->status], $a->askedAt],
        });

        return view('livewire.workspaces.question-board', [
            'shown' => $shown,
            'counts' => $counts,
            'topicNames' => collect($this->topics->list($by, $this->workspaceId))->pluck('name', 'id')->all(),
            // The page's own heading names the workspace and the module.
            'workspace' => $this->page ? $this->workspaces->find($by, $this->workspaceId) : null,
            'module' => $this->page && $this->moduleId !== null ? $this->modules->find($by, $this->moduleId) : null,
            // Across the course, each question says which module it is in.
            'moduleNames' => $this->moduleId === null && $this->sessionId === null ? collect($this->modules->list($by, $this->workspaceId))->pluck('title', 'id')->all() : [],
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

    /** This page's path, for the page of a new question to come back to (it checks it is this workspace's). */
    private function pagePath(): ?string
    {
        $url = url()->previous();
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return is_string($path) && str_starts_with($path, '/') ? $path.($query ? '?'.$query : '') : null;
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
