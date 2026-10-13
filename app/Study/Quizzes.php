<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Quizzes and tests as readable records (docs/specs/vistud-2-blueprint.md §3.7, §3.9): the tutor records one when it has
 * asked its questions (the `record_quiz` tool): what was asked, what the student answered, how it went, the score. The
 * answers also go to the journal as attempts, which is what mastery counts; this table is for reading back, and for the
 * progress screens to say "tested 90 %". A test also says which status each topic it touched would earn, to propose to
 * the student: 80 % or more understood, 50 % or less still confusing, otherwise covered. The student's own stream only.
 */
final class Quizzes
{
    public const KINDS = ['quiz', 'test'];

    public const MAX_QUESTIONS = 20;

    /** From this score a topic is understood; up to the other, still confusing. */
    public const UNDERSTOOD_FROM = 80;

    public const CONFUSING_UP_TO = 50;

    public function __construct(private Sessions $sessions, private Topics $topics, private WriteBack $writeBack, private Memory $memory) {}

    /**
     * Keeps a quiz or test of the session. Each question names the topic it was about, or takes the quiz's (or the
     * session's); the answers of those with a topic go to the journal as attempts. The score is worked out from the
     * results: a correct answer counts one, a partial one half. An answer that couldn't be marked (`unjudged`) is kept
     * as such, and counts neither way. $judge is the AI that marked the answers (WriteBack::apply).
     *
     * @param  array{kind?: mixed, topic?: mixed, questions?: mixed}  $input
     * @param  ?array{model: string, prompt: string, via: string}  $judge
     */
    public function record(Principal $by, string $sessionId, array $input, ?array $judge = null): QuizDetails
    {
        $scope = Guard::learner($by);
        $session = $this->sessions->find($by, $sessionId);
        $kind = $input['kind'] ?? null;
        $given = $input['questions'] ?? null;
        Input::refuse(array_filter([
            'kind' => in_array($kind, self::KINDS, true) ? null : 'Say whether it was a quiz or a test.',
            'questions' => ! is_array($given) || $given === [] ? 'Give the questions that were asked.' : (count($given) > self::MAX_QUESTIONS ? 'Keep it to '.self::MAX_QUESTIONS.' questions.' : null),
        ]));

        $topics = [];
        foreach ($this->topics->list($by, $session->workspaceId) as $topic) {
            $topics[mb_strtolower($topic->name)] = $topic;
        }
        $find = fn (mixed $name) => is_string($name) && trim($name) !== '' ? ($topics[mb_strtolower(trim($name))] ?? null) : null;
        $quizTopic = $find($input['topic'] ?? null) ?? ($session->topicId !== null ? collect($topics)->first(fn (TopicDetails $t) => $t->id === $session->topicId) : null);

        $questions = [];
        foreach (array_values($given) as $index => $item) {
            $item = is_array($item) ? $item : [];
            $asked = trim((string) ($item['asked'] ?? ''));
            $result = $item['result'] ?? null;
            Input::refuse(array_filter([
                "questions.{$index}" => match (true) {
                    $asked === '' => 'Say what was asked in question '.($index + 1).'.',
                    ! in_array($result, [...Capture::RESULTS, 'unjudged'], true) => 'Say how question '.($index + 1).' went: correct, partial or incorrect.',
                    default => null,
                },
            ]));
            $topic = $find($item['topic'] ?? null) ?? $quizTopic;
            $questions[] = [
                'asked' => mb_substr($asked, 0, Capture::LIMITS['asked']),
                'answer' => mb_substr(trim((string) ($item['answer'] ?? '')), 0, Capture::LIMITS['answer']),
                'result' => $result,
                'right' => mb_substr(trim((string) ($item['right'] ?? '')), 0, Capture::LIMITS['right']),
                'fix' => mb_substr(trim((string) ($item['fix'] ?? '')), 0, Capture::LIMITS['fix']),
                'topic' => $topic?->name,
                'topic_id' => $topic?->id,
            ];
        }

        $moduleId = $session->moduleId ?? $quizTopic?->moduleId;
        $now = CarbonImmutable::now('UTC');
        $since = LearnerTables::query($scope, 'quizzes')->where('session_id', $sessionId)->max('finished_at');
        $id = Ids::new();
        // What mastery counts: each answer, as an attempt on its topic.
        $attempts = [];
        foreach ($questions as $q) {
            if ($q['topic_id'] !== null) {
                $attempts[] = ['include' => true, 'kind' => 'attempt', 'topic_id' => $q['topic_id'], 'asked' => $q['asked'], 'answer' => $q['answer'], 'result' => $q['result'], 'right' => $q['right'], 'fix' => $q['fix'], 'form' => 'recall', 'support' => 'unaided'];
            }
        }

        // The quiz and its attempts are kept together, and the quiz says which attempts are its (so deleting it can take
        // exactly those back): one made without the other would leave answers counting for a quiz that isn't there.
        DB::transaction(function () use ($scope, $by, $session, $sessionId, $moduleId, $quizTopic, $kind, $questions, $input, $since, $now, $id, $attempts, $judge) {
            LearnerTables::insert($scope, 'quizzes', [
                'id' => $id, 'workspace_id' => $session->workspaceId, 'session_id' => $sessionId, 'module_id' => $moduleId,
                // A quiz in a folder's session is the folder's (docs/specs/vistud-2-blueprint.md, Phase 9).
                'folder_id' => $session->moduleId !== null && $session->moduleId === $moduleId ? $session->folderId : null, 'topic_id' => $quizTopic?->id,
                'kind' => $kind, 'questions' => json_encode($questions), 'attempt_ids' => json_encode([]), 'score' => self::score($questions), 'asked' => count($questions),
                'started_at' => CarbonImmutable::parse($input['started_at'] ?? ($since ?? $session->startedAt), 'UTC'), 'finished_at' => $now,
            ]);
            if ($attempts !== []) {
                $result = $this->writeBack->apply($by, $sessionId, $attempts, note: false, judge: $judge);
                LearnerTables::query($scope, 'quizzes')->where('id', $id)->update(['attempt_ids' => json_encode($result['ids']['attempt'] ?? [])]);
            }
        });

        return $this->find($by, $id);
    }

    /**
     * Deletes a quiz or test for good: its row goes, and the answers it wrote to the journal are retracted, so they stop
     * counting for the topics they were about (Replay: a retracted attempt is as if never made). The journal is append-only:
     * the answers and the correction both stay there as what happened. What the quiz marked on a topic (a test proposes or
     * sets a status) is the student's word on the topic and stays; it can be changed from the topic.
     */
    public function delete(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);

        DB::transaction(function () use ($scope, $by, $id) {
            $row = LearnerTables::query($scope, 'quizzes')->where('id', $id)->lockForUpdate()->first() ?? throw new NotFound;
            $attempts = $this->attemptsOf($scope, $row);
            if ($attempts !== []) {
                $this->memory->append($scope, [Memory::retract($scope, $by, $attempts)]);
            }
            LearnerTables::query($scope, 'quizzes')->where('id', $id)->delete();
        });
    }

    /**
     * The journal entries a quiz wrote. Those it names; for a quiz made before it did, the attempts the write-back made
     * for its questions in its session, within a minute of when it was recorded, by the question's task and outcome (each
     * attempt matched to one question, so a quiz never takes back another's answer to the same question).
     *
     * @return list<string>
     */
    private function attemptsOf(LearnerScope $scope, object $row): array
    {
        if ($row->attempt_ids !== null) {
            $ids = is_string($row->attempt_ids) ? json_decode($row->attempt_ids, true) : $row->attempt_ids;

            return array_values(array_filter(is_array($ids) ? $ids : [], 'is_string'));
        }

        $wanted = [];
        foreach (is_string($row->questions) ? (json_decode($row->questions, true) ?: []) : [] as $q) {
            if (is_array($q) && ($q['topic_id'] ?? null) !== null) {
                $wanted[WriteBack::taskIdFor((string) $row->workspace_id, (string) ($q['asked'] ?? ''))][] = (string) ($q['result'] ?? '');
            }
        }
        if ($wanted === []) {
            return [];
        }
        $recorded = CarbonImmutable::parse($row->finished_at, 'UTC');
        $entries = LearnerTables::query($scope, 'journal_entries')->where('kind', 'attempt')
            ->where('occurred_at', '>=', $recorded->format('Y-m-d H:i:s.u'))->where('occurred_at', '<=', $recorded->addMinute()->format('Y-m-d H:i:s.u'))
            ->when($row->session_id !== null, fn ($query) => $query->where('session_id', $row->session_id))
            ->orderBy('position')->get(['id', 'body']);

        $found = [];
        foreach ($entries as $entry) {
            $body = is_string($entry->body) ? (json_decode($entry->body, true) ?: []) : (array) $entry->body;
            $task = (string) ($body['task'] ?? '');
            $at = array_search((string) ($body['outcome'] ?? ''), $wanted[$task] ?? [], true);
            if (($body['setting'] ?? null) === 'chat' && $at !== false) {
                unset($wanted[$task][$at]);
                $found[] = (string) $entry->id;
            }
        }

        return $found;
    }

    public function find(Principal $by, string $id): QuizDetails
    {
        $scope = Guard::learner($by);

        return self::details(LearnerTables::query($scope, 'quizzes')->where('id', $id)->first() ?? throw new NotFound);
    }

    /** @return list<QuizDetails> a session's quizzes and tests, oldest first */
    public function forSession(Principal $by, string $sessionId): array
    {
        $scope = Guard::learner($by);
        $this->sessions->find($by, $sessionId);

        return LearnerTables::query($scope, 'quizzes')->where('session_id', $sessionId)->orderBy('finished_at')->orderBy('id')->get()->map(fn ($row) => self::details($row))->all();
    }

    /** @return list<QuizDetails> a module's quizzes and tests, newest first */
    public function forModule(Principal $by, string $moduleId): array
    {
        $scope = Guard::learner($by);
        LearnerTables::query($scope, 'modules')->where('id', $moduleId)->exists() || throw new NotFound;

        return LearnerTables::query($scope, 'quizzes')->where('module_id', $moduleId)->orderByDesc('finished_at')->orderByDesc('id')->get()->map(fn ($row) => self::details($row))->all();
    }

    /** @return list<QuizDetails> every quiz and test of a course, newest first */
    public function forWorkspace(Principal $by, string $workspaceId): array
    {
        $scope = Guard::learner($by);
        Input::workspace($scope, $workspaceId);

        return LearnerTables::query($scope, 'quizzes')->where('workspace_id', $workspaceId)->orderByDesc('finished_at')->orderByDesc('id')->get()->map(fn ($row) => self::details($row))->all();
    }

    /**
     * The score of these questions, 0 to 100: a correct answer counts one, a partial one half. Those that couldn't be
     * marked are left out.
     *
     * @param  list<array{result: string}>  $questions
     */
    public static function score(array $questions): int
    {
        $questions = array_values(array_filter($questions, fn (array $q) => $q['result'] !== 'unjudged'));
        if ($questions === []) {
            return 0;
        }
        $points = 0.0;
        foreach ($questions as $q) {
            $points += match ($q['result']) {
                'correct' => 1.0,
                'partial' => 0.5,
                default => 0.0,
            };
        }

        return (int) round(100 * $points / count($questions));
    }

    /** The status a score earns a topic: understood, still confusing or covered. */
    public static function statusFor(int $score): string
    {
        return $score >= self::UNDERSTOOD_FROM ? 'understood' : ($score <= self::CONFUSING_UP_TO ? 'confused' : 'covered');
    }

    private static function details(object $row): QuizDetails
    {
        $questions = array_map(fn (array $q) => [
            'asked' => (string) ($q['asked'] ?? ''), 'answer' => (string) ($q['answer'] ?? ''), 'result' => (string) ($q['result'] ?? 'incorrect'),
            'right' => (string) ($q['right'] ?? ''), 'fix' => (string) ($q['fix'] ?? ''), 'topic' => $q['topic'] ?? null, 'topic_id' => $q['topic_id'] ?? null,
        ], is_string($row->questions) ? (json_decode($row->questions, true) ?: []) : []);

        $proposals = [];
        if ($row->kind === 'test') {
            $byTopic = [];
            foreach ($questions as $q) {
                if ($q['topic_id'] !== null) {
                    $byTopic[$q['topic_id']] = ['name' => (string) $q['topic'], 'questions' => [...($byTopic[$q['topic_id']]['questions'] ?? []), $q]];
                }
            }
            foreach ($byTopic as $topicId => $group) {
                $score = self::score($group['questions']);
                $proposals[] = ['topic_id' => $topicId, 'topic' => $group['name'], 'score' => $score, 'status' => self::statusFor($score)];
            }
        }

        return new QuizDetails(
            $row->id, $row->workspace_id, $row->session_id, $row->module_id, $row->topic_id, $row->kind, $questions, (int) $row->score, (int) $row->asked,
            CarbonImmutable::parse($row->started_at, 'UTC')->toIso8601ZuluString('microsecond'), CarbonImmutable::parse($row->finished_at, 'UTC')->toIso8601ZuluString('microsecond'), $proposals,
        );
    }
}
