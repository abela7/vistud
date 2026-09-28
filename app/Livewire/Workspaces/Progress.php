<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\BulkActions;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Findings;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\TopicDetails;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A workspace's Progress section (docs/specs/study-memory.md §3): its
 * topics with the student's status and the evidence behind it, the
 * findings pinned to each, and the questions (App\Livewire\Workspaces\QuestionBoard). Every click is a journal event through the
 * services; the IDs the dialog acts on are locked.
 */
final class Progress extends Component
{
    use BulkActions, Notices;

    #[Locked]
    public string $workspaceId;

    public string $bulkModuleId = '';

    /** topic (new or rename), move, finding, or null when the dialog is closed. */
    #[Locked]
    public ?string $mode = null;

    /** The topic the dialog is about, or null for a new topic. */
    #[Locked]
    public ?string $targetId = null;

    /** The finding being edited; null adds one to the topic. */
    #[Locked]
    public ?string $findingId = null;

    /** The topics whose findings are showing. */
    #[Locked]
    public array $expanded = [];

    public string $name = '';

    public string $moduleId = '';

    public string $text = '';

    public string $topicId = '';

    /** Where a finding came from: note:{id}, file:{id} or empty. */
    public string $source = '';

    public string $locator = '';

    private Topics $topics;

    private Findings $findings;

    private Modules $modules;

    private Sessions $sessions;

    private Flashcards $flashcards;

    private PrincipalFactory $principals;

    public function boot(Topics $topics, Findings $findings, Modules $modules, Sessions $sessions, Flashcards $flashcards, PrincipalFactory $principals): void
    {
        $this->sessions = $sessions;
        $this->flashcards = $flashcards;
        $this->topics = $topics;
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

    public function openBulkMove(array $keys): void
    {
        $this->bulkKeys = $keys;
        $this->bulkModuleId = '';
        $this->dispatch('bulk-topic-move-open');
    }

    public function confirmBulkMove(): void
    {
        $this->bulk('move', $this->bulkKeys, ['moduleId' => $this->bulkModuleId ?: null]);
        $this->bulkKeys = [];
        $this->dispatch('bulk-topic-move-close');
        $this->dispatch('selection-clear');
    }

    public function openBulkRemove(array $keys): void
    {
        $this->bulkKeys = $keys;
        $this->dispatch('bulk-topic-remove-open');
    }

    public function confirmBulkRemove(): void
    {
        $this->bulk('remove', $this->bulkKeys);
        $this->bulkKeys = [];
        $this->dispatch('bulk-topic-remove-close');
        $this->dispatch('selection-clear');
    }

    protected function allowedBulkTypes(): array
    {
        return ['topic'];
    }

    protected function performBulkAction(string $action, array $items, array $payload): void
    {
        $by = $this->principal();
        $done = 0;

        if ($action === 'status') {
            $status = (string) ($payload['status'] ?? 'covered');
            foreach ($items as [$type, $id]) {
                try {
                    $this->topics->report($by, $id, $status);
                    $done++;
                } catch (NotFound) {
                    // Ignored
                }
            }
            $word = match ($status) {
                'understood' => 'Understood',
                'confused' => 'Confused',
                default => 'Covered',
            };
            $this->notify($done === 1 ? "1 topic marked {$word}." : "{$done} topics marked {$word}.");
            $this->dispatch('selection-clear');

            return;
        }

        if ($action === 'move') {
            $moduleId = $payload['moduleId'] ?? null;
            foreach ($items as [$type, $id]) {
                try {
                    $this->topics->move($by, $id, $moduleId ?: null);
                    $done++;
                } catch (NotFound) {
                    // Ignored
                }
            }
            $this->notify($done === 1 ? '1 topic moved.' : "{$done} topics moved.");
            $this->dispatch('selection-clear');

            return;
        }

        if (in_array($action, ['remove', 'delete'], true)) {
            foreach ($items as [$type, $id]) {
                try {
                    $this->topics->retire($by, $id);
                    $done++;
                } catch (NotFound) {
                    // Ignored
                }
            }
            $this->notify($done === 1 ? '1 topic removed.' : "{$done} topics removed.");
            $this->dispatch('selection-clear');

            return;
        }
    }

    /** Starts a study session on the topic and opens it; another open session says so. */
    public function study(string $topicId): void
    {
        try {
            // The clock and teaching the student chose last.
            $last = $this->sessions->lastChoices($this->principal(), $this->workspaceId);
            $session = $this->sessions->start($this->principal(), $this->workspaceId, $topicId, null, $last['pomodoro'], $last['tutoring']);
        } catch (Conflict) {
            // Another session is open: the start panel says so, and offers to go back to it or end it.
            $this->dispatch('study-start', topicId: $topicId);

            return;
        }
        $this->dispatch('session-changed');
        $this->redirectRoute('workspaces.sessions.show', [$this->workspaceId, $session->id], navigate: true);
    }

    // ---------- Findings ----------

    public function toggleFindings(string $topicId): void
    {
        $this->expanded = in_array($topicId, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$topicId]))
            : [...$this->expanded, $topicId];
    }

    /** Opens App\Livewire\Workspaces\FlashcardEditor for a card on the topic. */
    public function newFlashcard(string $topicId): void
    {
        $this->dispatch('flashcard-new', topicId: $topicId);
    }

    #[On('flashcards-changed')]
    public function refreshCards(): void
    {
        // Drawing again updates each topic's count of cards.
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

    /** Opens App\Livewire\Workspaces\QuestionBoard's panel for a new question, about the topic if one is given. */
    public function newQuestion(?string $topicId = null): void
    {
        $this->dispatch('question-new', topicId: $topicId);
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
                'finding' => $this->savedFinding($by),
                default => null,
            };
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        } catch (NotFound) {
            $this->addError(['finding' => 'text'][$this->mode] ?? 'moduleId', 'That no longer exists. Close this and try again.');

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
            'cards' => $this->flashcards->counts($by, $this->workspaceId)['topics'],
            'topicNames' => $topicNames,
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
