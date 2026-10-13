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

    /** @return list<object> the journal's entries of a kind, in order */
    private function entriesOf(string $kind): array
    {
        return array_values(array_filter(app(JournalReader::class)->entries($this->learnerScopeOf($this->ada)), fn ($e) => $e->kind->value === $kind));
    }

    private function attemptIdsOf(string $quizId): ?array
    {
        $ids = LearnerTables::query(LearnerScope::of($this->by), 'quizzes')->where('id', $quizId)->value('attempt_ids');

        return $ids === null ? null : json_decode($ids, true);
    }

    public function test_a_deleted_quiz_is_gone_and_takes_back_exactly_its_own_answers(): void
    {
        $first = $this->quizzes->record($this->by, $this->session, ['kind' => 'quiz', 'questions' => [$this->q('What is a quantum?', 'correct'), $this->q('What is aging?', 'incorrect')]]);
        $this->travel(10)->minutes();
        // The same question asked again later is another answer, and another quiz's.
        $second = $this->quizzes->record($this->by, $this->session, ['kind' => 'quiz', 'questions' => [$this->q('What is a quantum?', 'incorrect')]]);

        $attempts = $this->entriesOf('attempt');
        $this->assertCount(3, $attempts);
        $this->assertSame([$attempts[0]->id, $attempts[1]->id], $this->attemptIdsOf($first->id), 'a quiz names the answers it wrote');
        $this->assertSame([$attempts[2]->id], $this->attemptIdsOf($second->id));

        $this->quizzes->delete($this->by, $first->id);

        $this->assertSame([$second->id], array_map(fn ($q) => $q->id, $this->quizzes->forModule($this->by, $this->module)));
        $this->assertSame(0, LearnerTables::query(LearnerScope::of($this->by), 'quizzes')->where('id', $first->id)->count(), 'the row is gone, not hidden');
        $this->assertThrows(fn () => $this->quizzes->find($this->by, $first->id), NotFound::class);
        // The journal keeps what happened, with a correction that takes back the first quiz's answers and no others.
        $this->assertCount(3, $this->entriesOf('attempt'));
        $corrections = $this->entriesOf('amendment');
        $this->assertCount(1, $corrections);
        $this->assertSame(['retract', 'learner_request', ["event:{$attempts[0]->id}", "event:{$attempts[1]->id}"]], [$corrections[0]->body['action'], $corrections[0]->body['reason'], $corrections[0]->body['targets']]);
    }

    public function test_a_quiz_made_before_attempts_were_named_still_takes_back_its_own_answers(): void
    {
        $first = $this->quizzes->record($this->by, $this->session, ['kind' => 'quiz', 'questions' => [$this->q('What is a quantum?', 'correct'), $this->q('What is aging?', 'incorrect')]]);
        $this->travel(10)->minutes();
        $second = $this->quizzes->record($this->by, $this->session, ['kind' => 'quiz', 'questions' => [$this->q('What is a quantum?', 'correct')]]);
        $named = $this->attemptIdsOf($first->id);
        // As a quiz recorded before the ids were kept: found by session, question, outcome and time.
        LearnerTables::query(LearnerScope::of($this->by), 'quizzes')->update(['attempt_ids' => null]);

        $this->quizzes->delete($this->by, $first->id);

        $corrections = $this->entriesOf('amendment');
        $this->assertCount(1, $corrections);
        $this->assertSame(array_map(fn ($id) => "event:{$id}", $named), $corrections[0]->body['targets']);
        $this->assertCount(1, $this->quizzes->forModule($this->by, $this->module));
        $this->assertSame($second->id, $this->quizzes->forModule($this->by, $this->module)[0]->id);
    }

    public function test_a_quiz_with_no_answers_in_the_journal_is_deleted_without_a_correction(): void
    {
        // No topic on the session or the question: nothing was written as evidence, so nothing is taken back.
        app(Sessions::class)->setTopic($this->by, $this->session, null);
        $quiz = $this->quizzes->record($this->by, $this->session, ['kind' => 'quiz', 'questions' => [$this->q('Q', 'correct')]]);
        $this->assertSame([], $this->attemptIdsOf($quiz->id));

        $this->quizzes->delete($this->by, $quiz->id);

        $this->assertSame([], $this->entriesOf('amendment'));
        $this->assertSame(0, LearnerTables::query(LearnerScope::of($this->by), 'quizzes')->count());
    }

    public function test_another_students_quizzes_are_not_found(): void
    {
        $quiz = $this->quizzes->record($this->by, $this->session, ['kind' => 'quiz', 'questions' => [$this->q('Q', 'correct')]]);
        $grace = $this->principal($this->student());

        foreach ([fn () => $this->quizzes->delete($grace, $quiz->id), fn () => $this->quizzes->find($grace, $quiz->id), fn () => $this->quizzes->forSession($grace, $this->session), fn () => $this->quizzes->forModule($grace, $this->module), fn () => $this->quizzes->record($grace, $this->session, ['kind' => 'quiz', 'questions' => [$this->q('Q', 'correct')]])] as $try) {
            try {
                $try();
                $this->fail('Expected not found.');
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
