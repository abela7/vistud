<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The questions a student registers (docs/specs/study-memory.md §3): what
 * they don't get while they read or study, in a module, often in a study
 * session. Each is pending, stuck or answered, the student's word; an
 * answer can be written down. A question is an entity in the journal (a
 * `defines` claim), asked by a `question` event; "answered" records an
 * explanation that answered it and a `clicked` self-report, and "stuck" a
 * `confused` one, so the rules derive its state (ADR 0002 §7). The
 * student's own stream only.
 */
final class Questions
{
    public const MAX_TEXT = 1000;

    public const MAX_ANSWER = 2000;

    /** The statuses, in the order a question goes through them. */
    public const STATUSES = ['pending' => 'Pending', 'stuck' => 'Stuck', 'answered' => 'Answered'];

    public function __construct(private Memory $memory) {}

    /**
     * @param  ?string  $moduleId  only a module's questions (asked in it, or about one of its topics)
     * @param  ?string  $sessionId  only those asked in a study session
     * @param  ?array  $snapshot  the derived state, when the caller has taken it already (Memory::snapshot)
     * @return list<QuestionDetails> newest first, with the derived state of each
     */
    public function list(Principal $by, string $workspaceId, ?string $moduleId = null, ?string $sessionId = null, ?array $snapshot = null): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $query = LearnerTables::query($scope, 'questions')->where('workspace_id', $workspaceId)->whereNull('retired_at');
        if ($moduleId !== null) {
            $query->where('module_id', $moduleId);
        }
        if ($sessionId !== null) {
            $query->where('session_id', $sessionId);
        }
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $derived = ($snapshot ?? $this->memory->snapshot($scope))['questions'];

        return $rows->map(fn ($row) => self::details($row, $derived[$row->id] ?? null))->all();
    }

    public function find(Principal $by, string $id): QuestionDetails
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $id);

        return self::details($row, $this->memory->snapshot($scope)['questions'][$id] ?? null);
    }

    /**
     * Registers a question, about a topic of the workspace or none, in a
     * module (the one given, else the topic's). Asked while a study session
     * is open in the workspace, it belongs to that session unless another
     * is given.
     */
    public function ask(Principal $by, string $workspaceId, mixed $text, ?string $topicId = null, ?string $moduleId = null, ?string $sessionId = null): QuestionDetails
    {
        $scope = Guard::learner($by);
        $text = self::validatedText($text);
        [$id, $askId] = [Ids::new(), Ids::new()];

        DB::transaction(function () use ($scope, $by, $workspaceId, $topicId, $moduleId, $sessionId, $text, $id, $askId) {
            Input::workspace($scope, $workspaceId, lock: true);
            $topicId = $this->topicIn($scope, $workspaceId, $topicId);
            if ($moduleId !== null && $moduleId !== '') {
                LearnerTables::query($scope, 'modules')->where('id', $moduleId)->where('workspace_id', $workspaceId)->exists() || throw new NotFound;
            } else {
                $moduleId = $topicId === null ? null : LearnerTables::query($scope, 'topics')->where('id', $topicId)->value('module_id');
            }
            $sessionId ??= Sessions::openIn($scope, $workspaceId);
            $ask = Memory::observation($scope, $by, 'question', [], $topicId === null ? [] : [['rel' => 'about', 'target' => "topic:{$topicId}"]], ['text' => $text]);
            $ask['id'] = $askId;
            $this->memory->append($scope, [
                Memory::claim($scope, $by, 'defines', ["question:{$id}"], ['entity_type' => 'question', 'status' => 'active'], ['phrasing' => $text]),
                $ask,
                Memory::claim($scope, $by, 'refers_to', ["event:{$askId}"], ['entity' => "question:{$id}"]),
            ], $workspaceId);
            LearnerTables::insert($scope, 'questions', [
                'id' => $id, 'workspace_id' => $workspaceId, 'topic_id' => $topicId, 'module_id' => $moduleId, 'text' => $text,
                'status' => 'pending', 'session_id' => $sessionId, 'ask_event_id' => $askId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $this->find($by, $id);
    }

    /**
     * Moves a question to pending, stuck or answered, with the answer when
     * there is one. Answered records an explanation that answered it (the
     * answer as its text) and a `clicked` self-report; stuck, or pending
     * again after an answer, a `confused` one.
     */
    public function setStatus(Principal $by, string $id, string $status, mixed $answer = null): QuestionDetails
    {
        $scope = Guard::learner($by);
        $answer = $answer === null ? null : (Input::text(['v' => $answer], 'v') ?? '');
        Input::refuse(array_filter([
            'status' => isset(self::STATUSES[$status]) ? null : 'Choose pending, stuck or answered.',
            'answer' => $answer !== null && mb_strlen($answer) > self::MAX_ANSWER ? 'Keep the answer to '.self::MAX_ANSWER.' characters.' : null,
        ]));

        DB::transaction(function () use ($scope, $by, $id, $status, $answer) {
            $row = $this->row($scope, $id, lock: true);
            $was = $this->statusOf($scope, $row);
            $specs = [];
            if ($status === 'answered' && $was !== 'answered') {
                $about = $row->topic_id === null ? [] : [['rel' => 'about', 'target' => "topic:{$row->topic_id}"]];
                $specs[] = Memory::observation($scope, $by, 'exposure', ['format' => 'explanation'],
                    [['rel' => 'responds_to', 'target' => "event:{$row->ask_event_id}"], ...$about], $answer ? ['text' => $answer] : []);
                $specs[] = Memory::observation($scope, $by, 'self_report', ['stance' => 'clicked'], [['rel' => 'about', 'target' => "question:{$id}"]]);
            } elseif (($status === 'stuck' && $was !== 'stuck') || ($status === 'pending' && $was === 'answered')) {
                $specs[] = Memory::observation($scope, $by, 'self_report', ['stance' => 'confused'], [['rel' => 'about', 'target' => "question:{$id}"]]);
            }
            if ($specs !== []) {
                $this->memory->append($scope, $specs, $row->workspace_id);
            }
            $changes = ['status' => $status, 'answered_at' => $status === 'answered' ? ($row->answered_at ?? now()) : null, 'updated_at' => now()];
            if ($answer !== null) {
                $changes['answer'] = $answer === '' ? null : $answer;
            }
            LearnerTables::query($scope, 'questions')->where('id', $id)->update($changes);
        });

        return $this->find($by, $id);
    }

    /** The student understands it now. */
    public function resolve(Principal $by, string $id): void
    {
        $this->setStatus($by, $id, 'answered');
    }

    /** Still not clear after all. */
    public function reopen(Principal $by, string $id): void
    {
        $this->setStatus($by, $id, 'stuck');
    }

    /** New words for the question, or another module. */
    public function update(Principal $by, string $id, mixed $text, ?string $moduleId = null): QuestionDetails
    {
        $scope = Guard::learner($by);
        $text = self::validatedText($text);

        DB::transaction(function () use ($scope, $by, $id, $text, $moduleId) {
            $row = $this->row($scope, $id, lock: true);
            if ($moduleId !== null && $moduleId !== '') {
                LearnerTables::query($scope, 'modules')->where('id', $moduleId)->where('workspace_id', $row->workspace_id)->exists() || throw new NotFound;
            }
            if ($text !== $row->text) {
                $this->memory->append($scope, [
                    Memory::claim($scope, $by, 'defines', ["question:{$id}"], ['entity_type' => 'question', 'status' => 'active'], ['phrasing' => $text]),
                ], $row->workspace_id);
            }
            LearnerTables::query($scope, 'questions')->where('id', $id)->update([
                'text' => $text, 'module_id' => $moduleId === '' ? null : ($moduleId ?? $row->module_id), 'updated_at' => now(),
            ]);
        });

        return $this->find($by, $id);
    }

    public function setAskTeacher(Principal $by, string $id, bool $ask): void
    {
        $scope = Guard::learner($by);
        $this->row($scope, $id);
        LearnerTables::query($scope, 'questions')->where('id', $id)->update(['ask_teacher' => $ask, 'updated_at' => now()]);
    }

    /** Retires the question: it leaves the screens, and its events stay in the journal. */
    public function retire(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $id);

        $this->memory->append($scope, [
            Memory::claim($scope, $by, 'defines', ["question:{$id}"], ['entity_type' => 'question', 'status' => 'retired'], ['phrasing' => $row->text]),
        ], $row->workspace_id);
        LearnerTables::query($scope, 'questions')->where('id', $id)->update(['retired_at' => now(), 'updated_at' => now()]);
    }

    private function topicIn(LearnerScope $scope, string $workspaceId, ?string $topicId): ?string
    {
        if ($topicId === null || $topicId === '') {
            return null;
        }
        $topic = LearnerTables::query($scope, 'topics')->where('id', $topicId)->where('workspace_id', $workspaceId)->whereNull('retired_at')->first() ?? throw new NotFound;

        return $topic->id;
    }

    private function row(LearnerScope $scope, string $id, bool $lock = false): object
    {
        $query = LearnerTables::query($scope, 'questions')->where('id', $id)->whereNull('retired_at');

        return ($lock ? $query->lockForUpdate() : $query)->first() ?? throw new NotFound;
    }

    /** The status as the screens show it (see details()). */
    private function statusOf(LearnerScope $scope, object $row): string
    {
        return self::shownStatus($row, $this->memory->snapshot($scope)['questions'][$row->id] ?? null);
    }

    /**
     * The student's word; a question marked understood before questions had
     * statuses is answered.
     */
    private static function shownStatus(object $row, ?array $derived): string
    {
        $status = (string) ($row->status ?? 'pending');

        return $status === 'pending' && $row->answered_at === null && str_starts_with((string) ($derived['state'] ?? ''), 'resolved') ? 'answered' : $status;
    }

    private static function validatedText(mixed $text): string
    {
        $text = Input::text(['text' => $text], 'text');
        Input::refuse(array_filter(['text' => match (true) {
            $text === null => 'Write the question.',
            mb_strlen($text) > self::MAX_TEXT => 'Keep the question to '.self::MAX_TEXT.' characters.',
            default => null,
        }]));

        return $text;
    }

    private static function details(object $row, ?array $derived): QuestionDetails
    {
        return new QuestionDetails(
            $row->id, $row->workspace_id, $row->topic_id, $row->text, (bool) $row->ask_teacher,
            $derived['state'] ?? 'open', $derived['flags'] ?? [], Carbon::parse($row->created_at)->utc()->format('Y-m-d\TH:i:s.up'),
            $row->module_id, self::shownStatus($row, $derived), $row->answer, $row->session_id,
        );
    }
}
