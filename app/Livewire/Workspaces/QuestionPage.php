<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
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
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A question on a page of its own (docs/specs/study-memory.md §3, the owner's
 * review, 2026-09-29): the question and its answer, and beside them the
 * options: status, module, topic, whether to ask the teacher, and deleting
 * it. Also the page for a new question, which is made by its first save and
 * then goes back to where the student was (`from`, a page of this workspace)
 * or to the module's questions. The question's ID is locked.
 */
final class QuestionPage extends Component
{
    use Notices;

    #[Locked]
    public string $workspaceId;

    /** The question this page is for; null for a new one. */
    #[Locked]
    public ?string $questionId = null;

    /** A page of this workspace to go back to, or null. */
    #[Locked]
    public ?string $from = null;

    public string $question = '';

    public string $status = 'pending';

    public string $answer = '';

    public string $topicId = '';

    public string $moduleId = '';

    public bool $askTeacher = false;

    private Questions $questions;

    private Topics $topics;

    private Modules $modules;

    private Workspaces $workspaces;

    private PrincipalFactory $principals;

    public function boot(Questions $questions, Topics $topics, Modules $modules, Workspaces $workspaces, PrincipalFactory $principals): void
    {
        $this->questions = $questions;
        $this->topics = $topics;
        $this->modules = $modules;
        $this->workspaces = $workspaces;
        $this->principals = $principals;
    }

    /**
     * A new question starts in `$inModule` or about `$aboutTopic`; with `$withStatus` an open one has that status
     * chosen (answering it, say). (Not named like the properties: Livewire would assign them to those.)
     */
    public function mount(string $workspaceId, ?string $questionId = null, ?string $inModule = null, ?string $aboutTopic = null, ?string $from = null, ?string $withStatus = null): void
    {
        $by = $this->principal();
        [$this->workspaceId, $this->questionId] = [$workspaceId, $questionId];
        $this->from = $this->pageOfThisWorkspace($from);

        if ($questionId !== null) {
            $asked = $this->questions->find($by, $questionId);
            $asked->workspaceId === $workspaceId || throw new NotFound;
            [$this->question, $this->status, $this->answer, $this->topicId, $this->moduleId, $this->askTeacher] =
                [$asked->text, isset(Questions::STATUSES[$withStatus]) ? $withStatus : $asked->status, (string) $asked->answer, $asked->topicId ?? '', $asked->moduleId ?? '', $asked->askTeacher];

            return;
        }

        // A new one starts where it was asked: a module or a topic of this workspace, or neither.
        $topic = collect($this->topics->list($by, $workspaceId))->firstWhere('id', $aboutTopic);
        $module = collect($this->modules->list($by, $workspaceId))->firstWhere('id', $inModule ?? $topic?->moduleId);
        [$this->topicId, $this->moduleId] = [$topic?->id ?? '', $module?->id ?? ''];
    }

    /** The question was changed by a ✦ job (clarified, split, answered from the notes): show it as it is now. */
    #[On('questions-changed')]
    public function reloadQuestion(): void
    {
        if ($this->questionId === null) {
            return;
        }
        try {
            $asked = $this->questions->find($this->principal(), $this->questionId);
        } catch (NotFound) {
            return;
        }
        [$this->question, $this->status, $this->answer] = [$asked->text, $asked->status, (string) $asked->answer];
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $by = $this->principal();
        try {
            if ($this->questionId === null) {
                $id = $this->questions->ask($by, $this->workspaceId, $this->question, $this->topicId ?: null, $this->moduleId ?: null)->id;
            } else {
                $id = $this->questionId;
                $this->questions->update($by, $id, $this->question, $this->moduleId);
            }
            $this->questions->setStatus($by, $id, $this->status, $this->answer);
            $this->questions->setAskTeacher($by, $id, $this->askTeacher);
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError(['text' => 'question'][$field] ?? $field, $messages[0]);
            }

            return;
        } catch (NotFound) {
            $this->addError('question', 'That no longer exists. Go back and try again.');

            return;
        }

        if ($this->questionId === null) {
            session()->flash('workspace-notice', 'The question is saved.');
            $this->redirect($this->leaveTo(), navigate: true);

            return;
        }
        $this->notify('The question is saved.');
    }

    public function delete(): void
    {
        if ($this->questionId !== null) {
            try {
                $this->questions->retire($this->principal(), $this->questionId);
            } catch (NotFound) {
                // Already gone: the same end.
            }
            session()->flash('workspace-notice', 'The question is deleted.');
        }
        $this->redirect($this->listUrl(), navigate: true);
    }

    public function render(): View
    {
        $by = $this->principal();
        $asked = $this->asked($by);
        $topics = $this->topics->list($by, $this->workspaceId);
        $modules = $this->modules->list($by, $this->workspaceId);
        $module = collect($modules)->firstWhere('id', $this->moduleId);

        return view('livewire.workspaces.question-page', [
            'workspace' => $this->workspaces->find($by, $this->workspaceId),
            'asked' => $asked,
            'modules' => $modules,
            'module' => $module,
            'topicNames' => collect($topics)->pluck('name', 'id')->all(),
            // For a new question, the topics of the module it is in (all, with none).
            'topics' => $this->questionId !== null ? [] : array_values(array_filter($topics, fn ($t) => $this->moduleId === '' || $t->moduleId === $this->moduleId || $t->id === $this->topicId)),
            'backUrl' => $this->from ?? $this->listUrl(),
            'backTo' => $this->from !== null ? $this->fromName() : ($module !== null ? 'Questions' : 'Progress'),
        ]);
    }

    private function asked(Principal $by): ?QuestionDetails
    {
        if ($this->questionId === null) {
            return null;
        }
        try {
            return $this->questions->find($by, $this->questionId);
        } catch (NotFound) {
            return null;
        }
    }

    /** The module's questions, or Progress for one that has no module. */
    private function listUrl(): string
    {
        return $this->moduleId !== ''
            ? route('workspaces.modules.questions', [$this->workspaceId, $this->moduleId])
            : route('workspaces.show', [$this->workspaceId, 'progress']);
    }

    private function leaveTo(): string
    {
        return $this->from ?? $this->listUrl();
    }

    /** What a page the student came from is called, for the Back link. */
    private function fromName(): string
    {
        return match (true) {
            str_contains((string) $this->from, '/sessions/') => 'Study session',
            str_contains((string) $this->from, '/progress') => 'Progress',
            str_contains((string) $this->from, '/questions') => 'Questions',
            default => 'where you were',
        };
    }

    /** A page of this workspace (its path, and its query), never another site. */
    private function pageOfThisWorkspace(?string $from): ?string
    {
        if ($from === null || ! str_starts_with($from, "/workspaces/{$this->workspaceId}/") || str_contains($from, '//') || str_contains($from, '\\') || str_contains($from, '..')) {
            return null;
        }

        return url($from);
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
