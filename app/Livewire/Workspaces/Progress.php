<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Modules;
use App\Study\Questions;
use App\Study\TopicDetails;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A workspace's Progress section (docs/specs/study-memory.md §3): its
 * topics with the student's status and the evidence behind it, and the
 * questions they registered. Every click is a journal event through the
 * services; the IDs the dialog acts on are locked.
 */
final class Progress extends Component
{
    #[Locked]
    public string $workspaceId;

    /** topic (new or rename), move, question, or null when the dialog is closed. */
    #[Locked]
    public ?string $mode = null;

    #[Locked]
    public ?string $targetId = null;

    #[Locked]
    public bool $showUnderstood = false;

    public string $name = '';

    public string $moduleId = '';

    public string $text = '';

    public string $topicId = '';

    #[Locked]
    public ?string $notice = null;

    private Topics $topics;

    private Questions $questions;

    private Modules $modules;

    private PrincipalFactory $principals;

    public function boot(Topics $topics, Questions $questions, Modules $modules, PrincipalFactory $principals): void
    {
        $this->topics = $topics;
        $this->questions = $questions;
        $this->modules = $modules;
        $this->principals = $principals;
    }

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    // ---------- Topics ----------

    public function newTopic(?string $moduleId = null): void
    {
        $this->open('topic');
        $this->moduleId = (string) $moduleId;
    }

    public function renameTopic(string $id): void
    {
        $topic = $this->topics->find($this->principal(), $id);
        $this->open('topic', $topic->id);
        $this->name = $topic->name;
    }

    public function moveTopic(string $id): void
    {
        $topic = $this->topics->find($this->principal(), $id);
        $this->open('move', $topic->id);
        $this->moduleId = (string) $topic->moduleId;
    }

    /** The student's word: covered, understood or confused. */
    public function report(string $id, string $status): void
    {
        $this->topics->report($this->principal(), $id, $status);
        $this->notice = null;
    }

    public function moveTopicBy(string $id, int $step): void
    {
        $ids = array_map(fn (TopicDetails $t) => $t->id, $this->topics->list($this->principal(), $this->workspaceId));
        $index = array_search($id, $ids, true);
        if ($index !== false) {
            $this->topics->reorder($this->principal(), $id, max(0, $index + $step));
        }
    }

    public function retireTopic(string $id): void
    {
        $by = $this->principal();
        $name = $this->topics->find($by, $id)->name;
        $this->topics->retire($by, $id);
        $this->notice = "{$name} is removed. What you did on it stays in your journal.";
    }

    // ---------- Questions ----------

    public function newQuestion(?string $topicId = null): void
    {
        $this->open('question');
        $this->topicId = (string) $topicId;
    }

    public function resolveQuestion(string $id): void
    {
        $this->questions->resolve($this->principal(), $id);
    }

    public function reopenQuestion(string $id): void
    {
        $this->questions->reopen($this->principal(), $id);
    }

    public function toggleAskTeacher(string $id): void
    {
        $by = $this->principal();
        $question = $this->questions->find($by, $id);
        $this->questions->setAskTeacher($by, $id, ! $question->askTeacher);
    }

    public function retireQuestion(string $id): void
    {
        $this->questions->retire($this->principal(), $id);
        $this->notice = 'The question is removed.';
    }

    public function toggleUnderstood(): void
    {
        $this->showUnderstood = ! $this->showUnderstood;
    }

    // ---------- The dialog ----------

    public function save(): void
    {
        $by = $this->principal();
        $this->resetErrorBag();

        try {
            $this->notice = match ($this->mode) {
                'topic' => $this->targetId === null
                    ? $this->topics->create($by, $this->workspaceId, $this->name, $this->moduleId ?: null)->name.' is added.'
                    : $this->renamed($by),
                'move' => $this->moved($by),
                'question' => $this->questions->ask($by, $this->workspaceId, $this->text, $this->topicId ?: null) ? 'Your question is registered.' : null,
                default => null,
            };
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        } catch (NotFound) {
            $this->addError($this->mode === 'question' ? 'topicId' : 'moduleId', 'That no longer exists. Close this and try again.');

            return;
        }

        $this->close();
        $this->dispatch('progress-dialog-close');
    }

    public function close(): void
    {
        $this->reset('mode', 'targetId', 'name', 'moduleId', 'text', 'topicId');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $by = $this->principal();
        $modules = $this->modules->list($by, $this->workspaceId);
        $topics = $this->topics->list($by, $this->workspaceId);
        $questions = $this->questions->list($by, $this->workspaceId);

        $byModule = [];
        foreach ($topics as $topic) {
            $byModule[$topic->moduleId ?? ''][] = $topic;
        }
        $counts = array_fill_keys(['not_started', 'covered', 'understood', 'confused', 'mastered'], 0);
        foreach ($topics as $topic) {
            $counts[$topic->shown()]++;
        }
        $topicNames = [];
        foreach ($topics as $topic) {
            $topicNames[$topic->id] = $topic->name;
        }

        return view('livewire.workspaces.progress', [
            'modules' => $modules,
            'topics' => $topics,
            'byModule' => $byModule,
            'counts' => $counts,
            'topicNames' => $topicNames,
            'open' => array_values(array_filter($questions, fn ($q) => $q->shown() === 'open')),
            'understood' => array_values(array_filter($questions, fn ($q) => $q->shown() === 'understood')),
            'target' => $this->targetId === null ? null : ($topicNames[$this->targetId] ?? null),
        ]);
    }

    private function open(string $mode, ?string $targetId = null): void
    {
        $this->close();
        [$this->mode, $this->targetId] = [$mode, $targetId];
        $this->dispatch('progress-dialog-open');
    }

    private function renamed(Principal $by): string
    {
        $this->topics->rename($by, (string) $this->targetId, $this->name);

        return $this->topics->find($by, (string) $this->targetId)->name.' is renamed.';
    }

    private function moved(Principal $by): string
    {
        $this->topics->move($by, (string) $this->targetId, $this->moduleId ?: null);

        return $this->topics->find($by, (string) $this->targetId)->name.' is moved.';
    }

    private function principal(): Principal
    {
        return $this->principals->fromRequest(request());
    }
}
