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
 * they asked or didn't get. A question is an entity in the journal (a
 * `defines` claim), asked by a `question` event; "understood" records an
 * explanation that answered it and a `clicked` self-report, so the rules
 * derive its state (ADR 0002 §7). The student's own stream only.
 */
final class Questions
{
    public const MAX_TEXT = 1000;

    public function __construct(private Memory $memory) {}

    /** @return list<QuestionDetails> newest first, with the derived state of each */
    public function list(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);
        $rows = LearnerTables::query($scope, 'questions')->where('workspace_id', $workspaceId)->whereNull('retired_at')
            ->orderByDesc('created_at')->orderByDesc('id')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $derived = $this->memory->snapshot($scope)['questions'];

        return $rows->map(fn ($row) => self::details($row, $derived[$row->id] ?? null))->all();
    }

    public function find(Principal $by, string $id): QuestionDetails
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $id);

        return self::details($row, $this->memory->snapshot($scope)['questions'][$id] ?? null);
    }

    /** Registers a question, about a topic of the workspace or none. */
    public function ask(Principal $by, string $workspaceId, mixed $text, ?string $topicId = null): QuestionDetails
    {
        $scope = Guard::learner($by);
        $text = self::validatedText($text);
        [$id, $askId] = [Ids::new(), Ids::new()];

        DB::transaction(function () use ($scope, $by, $workspaceId, $topicId, $text, $id, $askId) {
            Input::workspace($scope, $workspaceId, lock: true);
            $topicId = $this->topicIn($scope, $workspaceId, $topicId);
            $ask = Memory::observation($scope, $by, 'question', [], $topicId === null ? [] : [['rel' => 'about', 'target' => "topic:{$topicId}"]], ['text' => $text]);
            $ask['id'] = $askId;
            $this->memory->append($scope, [
                Memory::claim($scope, $by, 'defines', ["question:{$id}"], ['entity_type' => 'question', 'status' => 'active'], ['phrasing' => $text]),
                $ask,
                Memory::claim($scope, $by, 'refers_to', ["event:{$askId}"], ['entity' => "question:{$id}"]),
            ]);
            LearnerTables::insert($scope, 'questions', [
                'id' => $id, 'workspace_id' => $workspaceId, 'topic_id' => $topicId, 'text' => $text, 'ask_event_id' => $askId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $this->find($by, $id);
    }

    /** The student understands it now: an explanation answered the question, and it clicked. */
    public function resolve(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $id);
        $about = $row->topic_id === null ? [] : [['rel' => 'about', 'target' => "topic:{$row->topic_id}"]];

        $this->memory->append($scope, [
            Memory::observation($scope, $by, 'exposure', ['format' => 'explanation'], [['rel' => 'responds_to', 'target' => "event:{$row->ask_event_id}"], ...$about]),
            Memory::observation($scope, $by, 'self_report', ['stance' => 'clicked'], [['rel' => 'about', 'target' => "question:{$id}"]]),
        ]);
        LearnerTables::query($scope, 'questions')->where('id', $id)->update(['updated_at' => now()]);
    }

    /** Still not clear after all: the question opens again. */
    public function reopen(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $this->row($scope, $id);

        $this->memory->append($scope, [
            Memory::observation($scope, $by, 'self_report', ['stance' => 'confused'], [['rel' => 'about', 'target' => "question:{$id}"]]),
        ]);
        LearnerTables::query($scope, 'questions')->where('id', $id)->update(['updated_at' => now()]);
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
        ]);
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

    private function row(LearnerScope $scope, string $id): object
    {
        return LearnerTables::query($scope, 'questions')->where('id', $id)->whereNull('retired_at')->first() ?? throw new NotFound;
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
        );
    }
}
