<?php

namespace App\Study;

use App\Brain\Store\JournalStore;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Flashcards (docs/specs/study-memory.md §4.5): a front and a back, in a
 * module and often on a topic, written by the student, made with an AI, or
 * saved from a study session. A card's module is its topic's when the topic has
 * one, else the module chosen for it, else the one of the session it was made
 * in. The student's own stream only.
 *
 * Reviewing follows a ladder of gaps: each "Got it" in a row waits longer
 * (1, 3, 7, 14, 30, 60, 120 days), "Partly" waits the same again, and "Not
 * yet" starts the card over from tomorrow. Every answer is also evidence in
 * the journal (ADR 0002): the card is a task that exercises its topic, and
 * the answer an attempt at recalling it, judged by the student.
 */
final class Flashcards
{
    public const MAX_FRONT = 500;

    public const MAX_BACK = 1000;

    /** Days to the next review after each "Got it" in a row. */
    public const LADDER = [1, 3, 7, 14, 30, 60, 120];

    /** The answers, as the journal names attempt outcomes. */
    public const RESULTS = ['correct', 'partial', 'incorrect'];

    /** The most cards in one round of review. */
    public const ROUND = 20;

    /** Days past its day after which a card counts as long overdue. */
    public const OVERDUE_DAYS = 7;

    public function __construct(private Memory $memory, private Sessions $sessions, private JournalStore $journal) {}

    /** @return list<FlashcardDetails> newest first; $topicId narrows to a topic, $moduleId to a module ('' to cards with none) */
    public function list(Principal $by, string $workspaceId, ?string $topicId = null, ?string $moduleId = null): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        return $this->query($scope, $workspaceId, $topicId, $moduleId)->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn ($row) => self::details($row))->all();
    }

    public function find(Principal $by, string $id): FlashcardDetails
    {
        return self::details($this->row(Guard::learner($by), $id));
    }

    /**
     * How many cards the workspace has (or a topic, '' for no topic), how
     * many are due today (new ones included), how many are new, when the
     * next are due after today and how many; and the same by topic.
     *
     * @return array{total: int, due: int, new: int, overdue: int, next_on: ?string, next_count: int, topics: array<string, array{total: int, due: int, overdue: int}>, modules: array<string, array{total: int, due: int, overdue: int}>}
     */
    public function counts(Principal $by, string $workspaceId, ?string $topicId = null, ?string $moduleId = null): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $today = $this->today($by);
        // Overdue is long overdue: a week or more past its day (docs/specs/vistud-2-blueprint.md §3.8).
        $overdueBy = CarbonImmutable::parse($today)->subDays(self::OVERDUE_DAYS)->toDateString();
        $counts = ['total' => 0, 'due' => 0, 'new' => 0, 'overdue' => 0, 'next_on' => null, 'next_count' => 0, 'topics' => [], 'modules' => []];
        foreach ($this->query($scope, $workspaceId, $topicId, $moduleId)->get(['topic_id', 'module_id', 'due_on']) as $row) {
            $dueOn = $row->due_on === null ? null : substr((string) $row->due_on, 0, 10);
            $due = $dueOn === null || $dueOn <= $today;
            $overdue = $dueOn !== null && $dueOn <= $overdueBy;
            $counts['overdue'] += (int) $overdue;
            $topic = (string) $row->topic_id;
            $counts['total']++;
            $counts['due'] += (int) $due;
            $counts['new'] += (int) ($dueOn === null);
            if (! $due) {
                if ($counts['next_on'] === null || $dueOn < $counts['next_on']) {
                    [$counts['next_on'], $counts['next_count']] = [$dueOn, 0];
                }
                $counts['next_count'] += (int) ($dueOn === $counts['next_on']);
            }
            $counts['topics'][$topic]['total'] = ($counts['topics'][$topic]['total'] ?? 0) + 1;
            $counts['topics'][$topic]['due'] = ($counts['topics'][$topic]['due'] ?? 0) + (int) $due;
            $counts['topics'][$topic]['overdue'] = ($counts['topics'][$topic]['overdue'] ?? 0) + (int) $overdue;
            $module = (string) $row->module_id;
            $counts['modules'][$module]['total'] = ($counts['modules'][$module]['total'] ?? 0) + 1;
            $counts['modules'][$module]['due'] = ($counts['modules'][$module]['due'] ?? 0) + (int) $due;
            $counts['modules'][$module]['overdue'] = ($counts['modules'][$module]['overdue'] ?? 0) + (int) $overdue;
        }

        return $counts;
    }

    /**
     * The cards for a round of review, at most ROUND of them: those due
     * (the longest waiting first, then new ones, oldest first), or, to
     * practise early, those not due yet (the soonest first).
     *
     * @return list<string> card ids
     */
    public function queue(Principal $by, string $workspaceId, ?string $topicId = null, bool $early = false, ?string $moduleId = null): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $today = $this->today($by);
        $query = $this->query($scope, $workspaceId, $topicId, $moduleId);
        $early
            ? $query->where('due_on', '>', $today)->orderBy('due_on')
            : $query->where(fn ($q) => $q->whereNull('due_on')->orWhere('due_on', '<=', $today))->orderByRaw('due_on is null')->orderBy('due_on');

        return $query->orderBy('created_at')->orderBy('id')->limit(self::ROUND)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function add(Principal $by, string $workspaceId, ?string $topicId, mixed $front, mixed $back, string $author = 'student', ?string $sessionId = null, ?string $moduleId = null): string
    {
        $scope = Guard::learner($by);
        [$front, $back] = self::validated($front, $back);
        $id = Ids::new();
        $author = in_array($author, ['student', 'ai'], true) ? $author : 'student';

        DB::transaction(function () use ($scope, $by, $workspaceId, $topicId, $moduleId, $front, $back, $author, $sessionId, $id) {
            Input::workspace($scope, $workspaceId, lock: true);
            $this->topicIn($scope, $workspaceId, $topicId);
            $moduleId = $this->moduleFor($scope, $workspaceId, $topicId, $moduleId, $sessionId);
            LearnerTables::insert($scope, 'flashcards', [
                'id' => $id, 'workspace_id' => $workspaceId, 'topic_id' => $topicId, 'module_id' => $moduleId, 'front' => $front, 'back' => $back,
                'author' => $author, 'session_id' => $sessionId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $specs = [self::taskRecord($scope, $by, $id, $front, $back, 1, 'active')];
            if ($topicId !== null) {
                $specs[] = self::exercises($scope, $by, $id, $topicId, 'active');
            }
            $this->memory->append($scope, $sessionId === null ? $specs : array_map(fn ($s) => $s + ['session' => $sessionId], $specs), $workspaceId);
        });

        return $id;
    }

    /**
     * Changes a card. New words make a new revision of its task (earlier
     * answers keep the words they answered); a new topic moves the claim of
     * what the card exercises. Its place on the ladder stays.
     */
    public function update(Principal $by, string $id, ?string $topicId, mixed $front, mixed $back, ?string $moduleId = null): void
    {
        $scope = Guard::learner($by);
        [$front, $back] = self::validated($front, $back);

        DB::transaction(function () use ($scope, $by, $id, $topicId, $moduleId, $front, $back) {
            $row = $this->row($scope, $id, lock: true);
            $this->topicIn($scope, $row->workspace_id, $topicId);
            // Left out, the card keeps its module; '' takes it out of every module.
            $moduleId = $this->moduleFor($scope, $row->workspace_id, $topicId, $moduleId ?? $row->module_id, null);
            $this->ensureTask($scope, $by, $row);
            $specs = [];
            $revision = (int) $row->revision;
            if ($front !== $row->front || $back !== $row->back) {
                $revision++;
                $specs[] = self::taskRecord($scope, $by, $id, $front, $back, $revision, 'active');
            }
            if ($topicId !== $row->topic_id) {
                if ($row->topic_id !== null) {
                    $specs[] = self::exercises($scope, $by, $id, $row->topic_id, 'retired');
                }
                if ($topicId !== null) {
                    $specs[] = self::exercises($scope, $by, $id, $topicId, 'active');
                }
            }
            if ($specs !== []) {
                $this->memory->append($scope, $specs, $row->workspace_id);
            }
            LearnerTables::query($scope, 'flashcards')->where('id', $id)->update([
                'topic_id' => $topicId, 'module_id' => $moduleId, 'front' => $front, 'back' => $back, 'revision' => $revision, 'updated_at' => now(),
            ]);
        });
    }

    /** Deletes a card. Its answers stay in the journal, as what happened. */
    public function retire(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        DB::transaction(function () use ($scope, $by, $id) {
            $row = $this->row($scope, $id, lock: true);
            if ($this->journal->existingEntities($scope, [['task', self::taskId($id)]]) !== []) {
                $this->memory->append($scope, [self::taskRecord($scope, $by, $id, $row->front, $row->back, (int) $row->revision, 'retired')], $row->workspace_id);
            }
            LearnerTables::query($scope, 'flashcards')->where('id', $id)->update(['retired_at' => now(), 'updated_at' => now()]);
        });
    }

    /**
     * Records an answer: an attempt in the journal, in the study session
     * open in the workspace (or the day's review, when none is), and, when
     * $schedule, the card's next date on the ladder. Practising early and a
     * second go in the same round don't move the card.
     */
    public function answer(Principal $by, string $id, string $result, bool $schedule = true): FlashcardDetails
    {
        Input::refuse(in_array($result, self::RESULTS, true) ? [] : ['result' => 'Choose how it went.']);
        $scope = Guard::learner($by);
        $today = $this->today($by);

        DB::transaction(function () use ($scope, $by, $id, $result, $schedule, $today) {
            $row = $this->row($scope, $id, lock: true);
            $this->ensureTask($scope, $by, $row);
            $session = Sessions::openIn($scope, $row->workspace_id)
                ?? 'cards-'.$today.'-'.substr(sha1($scope->learnerId.'|'.$row->workspace_id), 0, 16);
            $this->memory->append($scope, [Memory::observation($scope, $by, 'attempt', [
                'task' => self::taskId($id), 'task_revision' => (int) $row->revision, 'form' => 'recall', 'support' => 'unaided',
                'setting' => 'practice', 'outcome' => $result, 'judged_by' => 'self',
            ]) + ['session' => $session]]);

            $changes = ['reviews' => (int) $row->reviews + 1, 'last_result' => $result, 'last_reviewed_at' => now(), 'updated_at' => now()];
            if ($schedule) {
                [$step, $days] = self::next((int) $row->step, $result);
                $changes += [
                    'step' => $step, 'due_on' => CarbonImmutable::parse($today)->addDays($days)->toDateString(),
                    'lapses' => (int) $row->lapses + (int) ($result === 'incorrect'),
                ];
            }
            LearnerTables::query($scope, 'flashcards')->where('id', $id)->update($changes);
        });

        return $this->find($by, $id);
    }

    /**
     * Where an answer moves a card on the ladder: its new step and the days
     * until it's due again.
     *
     * @return array{0: int, 1: int}
     */
    public static function next(int $step, string $result): array
    {
        $top = count(self::LADDER);
        $step = max(0, min($step, $top));

        return match ($result) {
            'correct' => [min($step + 1, $top), self::LADDER[min($step, $top - 1)]],
            'partial' => [$step, $step === 0 ? 1 : self::LADDER[$step - 1]],
            default => [0, 1],
        };
    }

    /** "tomorrow", "3 days", "2 weeks", "1 month", "4 months". */
    public static function gapWords(int $days): string
    {
        return match (true) {
            $days <= 1 => 'tomorrow',
            $days < 14 => "{$days} days",
            $days < 30 => intdiv($days, 7).' weeks',
            $days < 60 => '1 month',
            default => intdiv($days, 30).' months',
        };
    }

    /** Names the cards already in the workspace, as App\Study\Capture fingerprints them, to spot repeats. */
    public function fingerprints(Principal $by, string $workspaceId): array
    {
        $prints = [];
        foreach ($this->list($by, $workspaceId) as $card) {
            $prints[Capture::fingerprint(['kind' => 'flashcard', 'front' => $card->front, 'back' => $card->back])] = true;
        }

        return $prints;
    }

    /** The student's date today, as the ladder counts days. */
    public function today(Principal $by): string
    {
        return CarbonImmutable::now($this->sessions->timezone($by))->toDateString();
    }

    public static function taskId(string $cardId): string
    {
        return 'card-'.$cardId;
    }

    private function query(LearnerScope $scope, string $workspaceId, ?string $topicId = null, ?string $moduleId = null)
    {
        $query = LearnerTables::query($scope, 'flashcards')->where('workspace_id', $workspaceId)->whereNull('retired_at');
        if ($topicId === '') {
            $query->whereNull('topic_id');
        } elseif ($topicId !== null) {
            $query->where('topic_id', $topicId);
        }
        if ($moduleId === '') {
            $query->whereNull('module_id');
        } elseif ($moduleId !== null) {
            $query->where('module_id', $moduleId);
        }

        return $query;
    }

    /**
     * A card's module: its topic's when the topic has one, else the one chosen (checked to be the workspace's),
     * else the module of the session it was made in.
     */
    private function moduleFor(LearnerScope $scope, string $workspaceId, ?string $topicId, ?string $moduleId, ?string $sessionId): ?string
    {
        if ($topicId !== null) {
            $topicModule = LearnerTables::query($scope, 'topics')->where('id', $topicId)->value('module_id');
            if ($topicModule !== null) {
                return (string) $topicModule;
            }
        }
        if ($moduleId !== null && $moduleId !== '') {
            LearnerTables::query($scope, 'modules')->where('id', $moduleId)->where('workspace_id', $workspaceId)->exists() || throw new NotFound;

            return $moduleId;
        }
        if ($sessionId !== null) {
            $sessionModule = LearnerTables::query($scope, 'study_sessions')->where('id', $sessionId)->value('module_id');

            return $sessionModule === null ? null : (string) $sessionModule;
        }

        return null;
    }

    private function row(LearnerScope $scope, string $id, bool $lock = false): object
    {
        $query = LearnerTables::query($scope, 'flashcards')->where('id', $id)->whereNull('retired_at');

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new NotFound;
    }

    private function topicIn(LearnerScope $scope, string $workspaceId, ?string $topicId): void
    {
        if ($topicId !== null) {
            LearnerTables::query($scope, 'topics')->where('id', $topicId)->where('workspace_id', $workspaceId)->whereNull('retired_at')->exists() || throw new NotFound;
        }
    }

    /** Cards saved before reviewing existed have no task yet: it's written on first use. */
    private function ensureTask(LearnerScope $scope, Principal $by, object $row): void
    {
        if ($this->journal->existingEntities($scope, [['task', self::taskId($row->id)]]) !== []) {
            return;
        }
        $specs = [self::taskRecord($scope, $by, $row->id, $row->front, $row->back, (int) $row->revision, 'active')];
        if ($row->topic_id !== null) {
            $specs[] = self::exercises($scope, $by, $row->id, $row->topic_id, 'active');
        }
        $this->memory->append($scope, $specs);
    }

    /** @return array{0: string, 1: string} */
    private static function validated(mixed $front, mixed $back): array
    {
        $front = Input::text(['v' => $front], 'v');
        $back = Input::text(['v' => $back], 'v');
        Input::refuse(array_filter([
            'front' => $front === null ? 'Write the front.' : (mb_strlen($front) > self::MAX_FRONT ? 'Keep it to '.self::MAX_FRONT.' characters.' : null),
            'back' => $back === null ? 'Write the back.' : (mb_strlen($back) > self::MAX_BACK ? 'Keep it to '.self::MAX_BACK.' characters.' : null),
        ]));

        return [$front, $back];
    }

    /** The card as a task (ADR 0002 §5): the front is the prompt, the back the answer. */
    private static function taskRecord(LearnerScope $scope, Principal $by, string $id, string $front, string $back, int $revision, string $status): array
    {
        $norm = fn (string $s) => mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s)));

        return [
            'id' => Ids::new(), 'kind' => 'record', 'actor' => Memory::actor($scope, $by), 'occurred_at' => Memory::now(),
            'body' => [
                'record_type' => 'task', 'record_id' => self::taskId($id), 'key' => 'card/'.$id, 'title' => mb_substr($front, 0, 120),
                'revision' => $revision, 'content_hash' => sha1($norm($front).'|'.$norm($back)), 'status' => $status,
            ],
            'content' => ['prompt' => $front, 'answer' => $back],
        ];
    }

    private static function exercises(LearnerScope $scope, Principal $by, string $id, string $topicId, string $status): array
    {
        return Memory::claim($scope, $by, 'relates', ['task:'.self::taskId($id), "topic:{$topicId}"], ['relation' => 'exercises', 'status' => $status]);
    }

    private static function details(object $row): FlashcardDetails
    {
        return new FlashcardDetails(
            (string) $row->id, (string) $row->workspace_id, $row->topic_id === null ? null : (string) $row->topic_id,
            (string) $row->front, (string) $row->back, (string) $row->author, $row->session_id === null ? null : (string) $row->session_id,
            (int) $row->revision, (int) $row->step, $row->due_on === null ? null : substr((string) $row->due_on, 0, 10),
            (int) $row->reviews, (int) $row->lapses, $row->last_result === null ? null : (string) $row->last_result, (string) $row->created_at,
            isset($row->module_id) ? (string) $row->module_id : null,
        );
    }
}
