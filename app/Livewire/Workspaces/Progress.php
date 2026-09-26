<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Findings;
use App\Study\Modules;
use App\Study\Questions;
use App\Study\TopicDetails;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A workspace's Progress section (docs/specs/study-memory.md §3): its
 * topics with the student's status and the evidence behind it, the
 * findings pinned to each, and the questions they registered. Every click is a journal event through the
 * services; the IDs the dialog acts on are locked.
 */
final class Progress extends Component
{
    #[Locked]
    public string $workspaceId;

    /** topic (new or rename), move, question, finding, or null when the dialog is closed. */
    #[Locked]
    public ?string $mode = null;

    /** The topic the dialog is about, or null for a new topic or a question. */
    #[Locked]
    public ?string $targetId = null;

    /** The finding being edited; null adds one to the topic. */
    #[Locked]
    public ?string $findingId = null;

    /** The topics whose findings are showing. */
    #[Locked]
    public array $expanded = [];

    #[Locked]
    public bool $showUnderstood = false;

    public string $name = '';

    public string $moduleId = '';

    public string $text = '';

    public string $topicId = '';

    /** Where a finding came from: note:{id}, file:{id} or empty. */
    public string $source = '';

    public string $locator = '';

    #[Locked]
    public ?string $notice = null;

    private Topics $topics;

    private Questions $questions;

    private Findings $findings;

    private Modules $modules;

    private PrincipalFactory $principals;

    public function boot(Topics $topics, Questions $questions, Findings $findings, Modules $modules, PrincipalFactory $principals): void
    {
        $this->topics = $topics;
        $this->questions = $questions;
        $this->findings = $findings;
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

    // ---------- Findings ----------

    public function toggleFindings(string $topicId): void
    {
        $this->expanded = in_array($topicId, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$topicId]))
            : [...$this->expanded, $topicId];
    }

    public function newFinding(string $topicId): void
    {
        $topic = $this->topics->find($this->principal(), $topicId);
        $this->open('finding', $topic->id);
    }

    public function editFinding(string $id): void
    {
        $finding = $this->findings->find($this->principal(), $id);
        $this->open('finding', $finding->topicId);
        $this->findingId = $finding->id;
        [$this->text, $this->source, $this->locator] = [$finding->text, $finding->sourceName === null ? '' : (string) $finding->source, (string) $finding->locator];
    }

    public function deleteFinding(string $id): void
    {
        $this->findings->delete($this->principal(), $id);
        $this->notice = 'The finding is removed.';
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
                'finding' => $this->savedFinding($by),
                default => null,
            };
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        } catch (NotFound) {
            $this->addError(['question' => 'topicId', 'finding' => 'text'][$this->mode] ?? 'moduleId', 'That no longer exists. Close this and try again.');

            return;
        }

        $this->close();
        $this->dispatch('progress-dialog-close');
    }

    public function close(): void
    {
        $this->reset('mode', 'targetId', 'findingId', 'name', 'moduleId', 'text', 'topicId', 'source', 'locator');
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
            'findings' => $this->findings->byTopic($by, $this->workspaceId),
            'sources' => $this->mode === 'finding' ? $this->findings->sources($by, $this->workspaceId) : [],
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

    private function savedFinding(Principal $by): string
    {
        $input = ['text' => $this->text, 'source' => $this->source, 'locator' => $this->locator];
        if ($this->findingId !== null) {
            $this->findings->update($by, $this->findingId, $input);

            return 'The finding is saved.';
        }
        $this->findings->add($by, (string) $this->targetId, $input);
        $this->expanded = array_values(array_unique([...$this->expanded, (string) $this->targetId]));

        return 'The finding is added.';
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
