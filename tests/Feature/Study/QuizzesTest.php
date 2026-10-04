<?php

namespace Tests\Feature\Study;

use App\Brain\Store\JournalReader;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Modules;
use App\Study\Quizzes;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Quizzes and tests as readable records, with their answers as evidence (docs/specs/vistud-2-blueprint.md §3.9). */
class QuizzesTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $module;

    private string $scheduling;

    private string $deadlocks;

    private string $session;

    private Quizzes $quizzes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 3'])->id;
        $topics = app(Topics::class);
        $this->scheduling = $topics->create($this->by, $this->workspace, 'CPU scheduling', $this->module)->id;
        $this->deadlocks = $topics->create($this->by, $this->workspace, 'Deadlocks', $this->module)->id;
        $this->session = app(Sessions::class)->start($this->by, $this->workspace, $this->scheduling, null, null, null, 'quiz')->id;
        $this->quizzes = app(Quizzes::class);
    }

    private function q(string $asked, string $result, ?string $topic = null): array
    {
        return ['asked' => $asked, 'answer' => 'An answer.', 'result' => $result, 'right' => 'The idea.', 'fix' => 'The detail.'] + ($topic === null ? [] : ['topic' => $topic]);
    }

    public function test_a_quiz_is_kept_with_its_score_and_goes_to_the_sessions_topic(): void
    {
        $quiz = $this->quizzes->record($this->by, $this->session, ['kind' => 'quiz', 'questions' => [
            $this->q('What is a quantum?', 'correct'), $this->q('What is round robin?', 'correct'), $this->q('Name a policy.', 'partial'), $this->q('What is starvation?', 'incorrect'), $this->q('What is aging?', 'correct'),
        ]]);

        // Correct counts one, partial a half: 3.5 of 5 is 70 %.
        $this->assertSame(['quiz', 5, 70, 3], [$quiz->kind, $quiz->asked, $quiz->score, $quiz->right()]);
        $this->assertSame([$this->scheduling, $this->module, $this->session], [$quiz->topicId, $quiz->moduleId, $quiz->sessionId]);
        $this->assertSame('CPU scheduling', $quiz->questions[0]['topic']);
        $this->assertSame([], $quiz->proposals);
        $this->assertSame([$quiz->id], array_map(fn ($q) => $q->id, $this->quizzes->forSession($this->by, $this->session)));
        $this->assertSame([$quiz->id], array_map(fn ($q) => $q->id, $this->quizzes->forModule($this->by, $this->module)));

        // Every answer is evidence too: five attempts on the topic, in this session.
        $entries = app(JournalReader::class)->entries($this->learnerScopeOf($this->ada));
        $attempts = array_values(array_filter($entries, fn ($e) => $e->kind->value === 'attempt'));
        $this->assertSame(['correct', 'correct', 'partial', 'incorrect', 'correct'], array_map(fn ($e) => $e->body['outcome'], $attempts));
        $this->assertSame([$this->session], array_values(array_unique(array_map(fn ($e) => $e->sessionId, $attempts))));
    }

    public function test_a_test_says_which_status_each_topic_earned(): void
    {
        $quiz = $this->quizzes->record($this->by, $this->session, ['kind' => 'test', 'questions' => [
            $this->q('Q1', 'correct', 'CPU scheduling'), $this->q('Q2', 'correct', 'CPU scheduling'), $this->q('Q3', 'correct', 'CPU scheduling'), $this->q('Q4', 'correct', 'CPU scheduling'), $this->q('Q5', 'partial', 'CPU scheduling'),
            $this->q('Q6', 'incorrect', 'Deadlocks'), $this->q('Q7', 'partial', 'deadlocks'), $this->q('Q8', 'incorrect', 'Deadlocks'), $this->q('Q9', 'correct', 'Deadlocks'),
        ]]);

        $by = array_column($quiz->proposals, null, 'topic');
        // 4.5 of 5 is 90 % (understood); 1.5 of 4 is 38 % (still confusing).
        $this->assertSame([90, 'understood'], [$by['CPU scheduling']['score'], $by['CPU scheduling']['status']]);
        $this->assertSame([38, 'confused'], [$by['Deadlocks']['score'], $by['Deadlocks']['status']]);
        $this->assertSame('covered', Quizzes::statusFor(65));
        $this->assertSame(['understood', 'understood', 'covered', 'confused', 'confused'], array_map(Quizzes::statusFor(...), [100, 80, 51, 50, 0]));
        $this->assertSame(0, Quizzes::score([]));
    }

    public function test_a_question_with_no_topic_of_its_own_takes_the_quizs_and_an_unknown_one_is_left_to_it(): void
    {
        $quiz = $this->quizzes->record($this->by, $this->session, ['kind' => 'quiz', 'topic' => 'Deadlocks', 'questions' => [
            $this->q('Q1', 'correct'), $this->q('Q2', 'incorrect', 'Not a topic'), $this->q('Q3', 'correct', 'CPU scheduling'),
        ]]);

        $this->assertSame([$this->deadlocks, $this->deadlocks, $this->scheduling], array_column($quiz->questions, 'topic_id'));
        $this->assertSame($this->deadlocks, $quiz->topicId);
    }

    public function test_it_refuses_what_it_cannot_keep(): void
    {
        $refused = function (array $input): array {
            try {
                $this->quizzes->record($this->by, $this->session, $input);
                $this->fail('Expected a refusal.');
            } catch (Unprocessable $e) {
                return array_keys($e->details['fields'] ?? []);
            }
        };
        $this->assertSame(['kind'], $refused(['kind' => 'exam', 'questions' => [$this->q('Q', 'correct')]]));
        $this->assertSame(['questions'], $refused(['kind' => 'quiz', 'questions' => []]));
        $this->assertSame(['questions'], $refused(['kind' => 'quiz', 'questions' => array_fill(0, 21, $this->q('Q', 'correct'))]));
        $this->assertSame(['questions.0'], $refused(['kind' => 'quiz', 'questions' => [$this->q('', 'correct')]]));
        $this->assertSame(['questions.1'], $refused(['kind' => 'quiz', 'questions' => [$this->q('Q', 'correct'), ['asked' => 'Q', 'result' => 'maybe']]]));
        $this->assertSame(0, LearnerTables::query(LearnerScope::of($this->by), 'quizzes')->count());
    }

    public function test_another_students_quizzes_are_not_found(): void
    {
        $quiz = $this->quizzes->record($this->by, $this->session, ['kind' => 'quiz', 'questions' => [$this->q('Q', 'correct')]]);
        $grace = $this->principal($this->student());

        foreach ([fn () => $this->quizzes->find($grace, $quiz->id), fn () => $this->quizzes->forSession($grace, $this->session), fn () => $this->quizzes->forModule($grace, $this->module), fn () => $this->quizzes->record($grace, $this->session, ['kind' => 'quiz', 'questions' => [$this->q('Q', 'correct')]])] as $try) {
            try {
                $try();
                $this->fail('Expected not found.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
