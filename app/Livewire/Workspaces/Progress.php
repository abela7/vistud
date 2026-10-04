<?php

namespace App\Livewire\Workspaces;

use App\Identity\PrincipalFactory;
use App\Livewire\Concerns\BulkActions;
use App\Livewire\Concerns\Notices;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Modules;
use App\Study\Rollups;
use App\Study\Sessions;
use App\Study\TopicRoll;
use App\Study\Topics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A workspace's Progress section (docs/specs/vistud-2-blueprint.md §3.5.5, §3.8): the course's ring and its numbers, then
 * the tree: modules (the one the student is in open, the rest folded) and under each its topics with where they stand,
 * what needs another look, and Study. A topic's name opens the topic sheet (App\Livewire\Workspaces\TopicSheet), where the
 * student says where they stand; the tutor sets statuses in the chat. The numbers are App\Study\Rollups', the same the course
 * home and the Modules page show. Every click goes through the services; the IDs the dialog acts on are locked.
 */
final class Progress extends Component
{
    use BulkActions, Notices;

    /** What the filter row offers. */
    public const FILTERS = ['all', 'attention', 'not_started', 'mastered'];

    #[Locked]
    public string $workspaceId;

    /** All, the topics that need attention, those not started, or those mastered. */
    #[Url(except: 'all')]
    public string $filter = 'all';

    public string $bulkModuleId = '';

    /** topic (a new one) or move, or null when the dialog is closed. */
    #[Locked]
    public ?string $mode = null;

    /** The topic the dialog is about, or null for a new topic. */
    #[Locked]
    public ?string $targetId = null;

    public string $name = '';

    public string $moduleId = '';

    private Topics $topics;

    private Modules $modules;

    private Sessions $sessions;

    private Rollups $rollups;

    private PrincipalFactory $principals;

    public function boot(Topics $topics, Modules $modules, Sessions $sessions, Rollups $rollups, PrincipalFactory $principals): void
    {
        $this->sessions = $sessions;
        $this->rollups = $rollups;
        $this->topics = $topics;
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

    public function moveTopic(string $id): void
    {
        $topic = $this->topics->find($this->principal(), $id);
        $this->open('move', $topic->id);
        $this->moduleId = (string) $topic->moduleId;
    }

    /** Moves a topic one place up or down among its module's topics. */
    public function moveTopicBy(string $id, int $step): void
    {
        $all = $this->topics->list($this->principal(), $this->workspaceId);
        $ids = array_map(fn ($topic) => $topic->id, $all);
        $mine = array_values(array_filter($all, fn ($topic) => $topic->id === $id))[0] ?? null;
        if ($mine === null) {
            return;
        }
        $same = array_values(array_map(fn ($topic) => $topic->id, array_filter($all, fn ($topic) => $topic->moduleId === $mine->moduleId)));
        $sibling = $same[(int) array_search($id, $same, true) + $step] ?? null;
        if ($sibling !== null && array_search($id, $same, true) + $step >= 0) {
            $this->topics->reorder($this->principal(), $id, (int) array_search($sibling, $ids, true));
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

    // ---------- Cards and questions ----------

    /** Opens App\Livewire\Workspaces\FlashcardEditor for a card on the topic. */
    public function newFlashcard(string $topicId): void
    {
        $this->dispatch('flashcard-new', topicId: $topicId);
    }

    #[On('flashcards-changed')]
    #[On('topics-changed')]
    public function refreshTree(): void
    {
        // Drawing again updates the numbers: cards, statuses, names.
    }

    // ---------- Questions ----------

    /** Opens App\Livewire\Workspaces\QuestionBoard's panel for a new question, about the topic if one is given. */
    public function newQuestion(?string $topicId = null): void
    {
        $this->dispatch('question-new', topicId: $topicId);
    }

    // ---------- The filter ----------

    public function showOnly(string $filter): void
    {
        $this->filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
    }

    // ---------- The dialog ----------

    public function save(): void
    {
        $by = $this->principal();
        $this->resetErrorBag();

        try {
            $this->notice = match ($this->mode) {
                'topic' => $this->topics->create($by, $this->workspaceId, $this->name, $this->moduleId ?: null)->name.' is added.',
                'move' => $this->moved($by),
                default => null,
            };
        } catch (Unprocessable $e) {
            foreach ($e->details['fields'] ?? [] as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        } catch (NotFound) {
            $this->addError('moduleId', 'That no longer exists. Close this and try again.');

            return;
        }

        $this->close();
        $this->dispatch('progress-dialog-close');
    }

    public function close(): void
    {
        $this->reset('mode', 'targetId', 'name', 'moduleId');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $by = $this->principal();
        $roll = $this->rollups->for($by, $this->workspaceId);
        $filter = in_array($this->filter, self::FILTERS, true) ? $this->filter : 'all';
        $names = [];
        foreach ($roll->topics() as $topic) {
            $names[$topic->id()] = $topic->topic->name;
        }

        return view('livewire.workspaces.progress', [
            'roll' => $roll,
            'filter' => $filter,
            'modules' => $this->modules->list($by, $this->workspaceId),
            'counts' => ['all' => $roll->total(), 'attention' => count($roll->attention), 'not_started' => $roll->notStarted(), 'mastered' => $roll->mastered()],
            'target' => $this->targetId === null ? null : ($names[$this->targetId] ?? null),
        ]);
    }

    /** Whether a topic shows under the filter. */
    public static function shows(string $filter, TopicRoll $topic): bool
    {
        return match ($filter) {
            'attention' => $topic->needsAttention(),
            'not_started' => ! $topic->started(),
            'mastered' => $topic->shown === 'mastered',
            default => true,
        };
    }

    private function open(string $mode, ?string $targetId = null): void
    {
        $this->close();
        [$this->mode, $this->targetId] = [$mode, $targetId];
        $this->dispatch('progress-dialog-open');
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
