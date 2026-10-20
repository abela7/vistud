<?php

namespace Tests\Feature\Engine;

use App\Engine\Toolbox;
use App\Engine\Tools\Context;
use App\Models\User;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Study\Briefings;
use App\Study\Modules;
use App\Study\Quizzes;
use App\Study\Sessions;
use App\Study\TopicDetails;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * What the AI is told and what its tools change stay true to the course: two things with one name can be told apart, a
 * word is only put in someone's mouth when they said it, and a quiz only counts answers for topics they were about.
 */
class ToolTruthTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private Principal $by;

    private string $course;

    private string $week1;

    private string $week2;

    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->by = $this->principal($this->student());
        $this->course = app(Workspaces::class)->create($this->by, ['name' => 'Databases'])->id;
        $modules = app(Modules::class);
        $this->week1 = $modules->create($this->by, $this->course, ['title' => 'Week 1'])->id;
        $this->week2 = $modules->create($this->by, $this->course, ['title' => 'Week 2'])->id;
        $joins = app(Topics::class)->create($this->by, $this->course, 'Outer joins', $this->week2);
        $session = app(Sessions::class)->start($this->by, $this->course, $joins->id, $this->week2);
        $this->context = new Context($this->course, $this->week2, $session->id, 'UTC');
    }

    private function tool(string $tool, array $input = []): string
    {
        return app(Toolbox::class)->run($this->by, $this->context, $tool, $input);
    }

    public function test_a_name_in_quotes_or_with_extra_spaces_is_still_the_exact_name(): void
    {
        $topics = app(Topics::class);
        $self = $topics->create($this->by, $this->course, 'Self joins', $this->week2);
        // "Self joins" is part of "Self joins and views": only the exact name picks it out.
        $topics->create($this->by, $this->course, 'Self joins and views', $this->week2);

        $this->assertStringContainsString('Marked "Self joins" as understood', $this->tool('set_topic_status', ['topic' => ' "self  JOINS" ', 'status' => 'understood', 'reason' => 'Right first time.']));
        $this->assertSame('understood', $topics->find($this->by, $self->id)->status);
    }

    public function test_two_topics_with_one_name_are_told_apart_by_their_ids(): void
    {
        $topics = app(Topics::class);
        $first = $topics->create($this->by, $this->course, 'Introduction', $this->week1);
        $second = $topics->create($this->by, $this->course, 'Introduction', $this->week2);

        $out = $this->tool('set_topic_status', ['topic' => 'Introduction', 'status' => 'covered', 'reason' => 'We went through it.']);
        $this->assertStringContainsString("(id {$first->id})", $out);
        $this->assertStringContainsString("(id {$second->id})", $out);
        $this->assertNull($topics->find($this->by, $second->id)->status);

        $this->assertStringContainsString('Marked "Introduction" as covered', $this->tool('set_topic_status', ['topic' => $second->id, 'status' => 'covered', 'reason' => 'We went through it.']));
        $this->assertSame([null, 'covered'], [$topics->find($this->by, $first->id)->status, $topics->find($this->by, $second->id)->status]);
    }

    public function test_a_test_counts_no_answer_for_a_topic_the_course_does_not_have(): void
    {
        $topics = app(Topics::class);
        $outer = collect($topics->list($this->by, $this->course))->firstWhere('name', 'Outer joins');

        $out = $this->tool('record_quiz', ['kind' => 'test', 'questions' => [
            ['asked' => 'What is a natural join?', 'answer' => 'No idea', 'result' => 'incorrect', 'topic' => 'Natural joins'],
            ['asked' => 'And another?', 'answer' => 'Still no', 'result' => 'incorrect', 'topic' => 'Natural joins'],
        ]]);

        // Not put on the session's topic: that would call "Outer joins" confusing for answers about another topic.
        $this->assertStringContainsString('question 1 named "Natural joins", not a topic here, so it counts for no topic', $out);
        $this->assertNull($topics->find($this->by, $outer->id)->status);
        $quiz = app(Quizzes::class)->forSession($this->by, $this->context->sessionId)[0];
        $this->assertSame([null, null], array_column($quiz->questions, 'topic_id'));
        $this->assertSame([], $quiz->proposals);

        // The same name give or take a plural is that topic.
        $this->tool('record_quiz', ['kind' => 'test', 'questions' => [
            ['asked' => 'What does a left outer join keep?', 'answer' => 'The left rows', 'result' => 'correct', 'topic' => 'Outer join'],
        ]]);
        $this->assertSame('understood', $topics->find($this->by, $outer->id)->status);
    }

    public function test_answers_that_could_not_be_marked_propose_no_status(): void
    {
        $topics = app(Topics::class);
        $outer = collect($topics->list($this->by, $this->course))->firstWhere('name', 'Outer joins');

        $out = $this->tool('record_quiz', ['kind' => 'test', 'questions' => [
            ['asked' => 'What does a full outer join keep?', 'answer' => 'Both sides', 'result' => 'unjudged', 'topic' => 'Outer joins'],
            ['asked' => 'And a right one?', 'answer' => 'The right rows', 'result' => 'unjudged', 'topic' => 'Outer joins'],
        ]]);

        $this->assertStringContainsString('none of its 2 answers could be marked', $out);
        $this->assertNull($topics->find($this->by, $outer->id)->status);
        $this->assertSame([], app(Quizzes::class)->forSession($this->by, $this->context->sessionId)[0]->proposals);
    }

    public function test_a_word_overtaken_by_practice_is_not_put_in_the_students_mouth(): void
    {
        $topics = app(Topics::class);
        $sessions = app(Sessions::class);
        $sessions->end($this->by, $this->context->sessionId);
        $cross = $topics->create($this->by, $this->course, 'Cross joins', $this->week2);
        $topics->report($this->by, $cross->id, 'not_started');

        // Studied since: the topic shows as studying, from the practice, not from anything the student said.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:05:00', 'UTC'));
        $session = $sessions->start($this->by, $this->course, $cross->id, $this->week2);
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:20:00', 'UTC'));
        $sessions->touch($this->by, $session->id);
        $sessions->end($this->by, $session->id);

        $topic = $topics->find($this->by, $cross->id);
        $this->assertSame(['covered', 'not_started'], [$topic->shown(), $topic->status]);
        $this->assertFalse($topic->saidShown());

        $row = collect(json_decode($this->tool('topics'), true))->firstWhere('topic', 'Cross joins');
        $this->assertSame('covered', $row['status']);
        $this->assertSame('the evidence, since the student said not started', $row['set_by']);
        $this->assertArrayNotHasKey('set_at', $row);

        // Their own word that still stands is theirs.
        $topics->report($this->by, $cross->id, 'understood');
        $row = collect(json_decode($this->tool('topics'), true))->firstWhere('topic', 'Cross joins');
        $this->assertSame(['understood', 'the student'], [$row['status'], $row['set_by']]);
    }

    public function test_a_quiz_records_how_each_question_exercised_the_topic_so_mastery_can_be_earned(): void
    {
        $question = fn (string $asked, array $more = []) => ['asked' => $asked, 'answer' => 'Their answer', 'result' => 'correct', 'topic' => 'Outer joins'] + $more;

        // A quiz's questions recall; a test's apply, which is what secure and durable count; the tutor can say otherwise.
        $this->tool('record_quiz', ['kind' => 'quiz', 'questions' => [$question('Q1'), $question('Q2', ['form' => 'explain'])]]);
        $this->tool('record_quiz', ['kind' => 'test', 'questions' => [$question('Q3'), $question('Q4', ['form' => 'recognise'])]]);
        // The quiz panel says what kind of question each was.
        app(Quizzes::class)->record($this->by, $this->context->sessionId, ['kind' => 'quiz', 'questions' => [
            $question('Q5', ['type' => 'best']), $question('Q6', ['type' => 'short']), $question('Q7', ['type' => 'write']),
        ]]);

        $forms = [];
        foreach (app(Quizzes::class)->forSession($this->by, $this->context->sessionId) as $quiz) {
            foreach ($quiz->questions as $q) {
                $forms[$q['asked']] = $q['form'];
            }
        }
        $this->assertSame(['Q1' => 'recall', 'Q2' => 'explain', 'Q3' => 'apply', 'Q4' => 'recognise', 'Q5' => 'recognise', 'Q6' => 'recall', 'Q7' => 'apply'], $forms);

        // The same forms went to the journal, where the rules read them.
        $written = [];
        foreach (LearnerTables::query(LearnerScope::of($this->by), 'journal_entries')->where('type', 'core.attempt')->orderBy('position')->pluck('body') as $body) {
            $written[] = json_decode((string) $body, true)['form'];
        }
        $this->assertSame(['recall', 'explain', 'apply', 'recognise', 'recognise', 'recall', 'apply'], $written);
    }

    public function test_said_shown_is_only_true_for_the_word_that_still_stands(): void
    {
        $topic = fn (?string $status, string $label) => new TopicDetails('t', 'w', null, 'Joins', $status, 1, $label, [], null, $status === null ? null : 'stated', $status === null ? null : '2026-10-05T09:00:00Z');

        $this->assertTrue($topic('understood', 'developing')->saidShown());
        // Mastered from the evidence: shown "mastered", which nobody said.
        $this->assertSame('mastered', $topic('understood', 'secure')->shown());
        $this->assertFalse($topic('understood', 'secure')->saidShown());
        $this->assertFalse($topic(null, 'developing')->saidShown());
    }

    public function test_each_status_source_is_named_honestly_in_the_topics_tool_stack_and_briefing(): void
    {
        $topics = app(Topics::class);
        $outer = collect($topics->list($this->by, $this->course))->firstWhere('name', 'Outer joins');
        $mcp = $this->principal(User::findOrFail($this->by->userId), channel: 'mcp');

        $setBy = fn (?Principal $who = null) => collect(json_decode(app(Toolbox::class)->run($who ?? $this->by, $this->context, 'topics', []), true))->firstWhere('topic', 'Outer joins')['set_by'];

        // none
        $this->assertSame('nobody yet', $setBy());

        // stated
        $topics->report($this->by, $outer->id, 'understood');
        $this->assertSame('the student', $setBy());

        // accepted
        $topics->accept($this->by, $outer->id, 'covered');
        $this->assertSame("the student (accepted the AI's word)", $setBy());

        // stated_unverified (legacy 'student')
        LearnerTables::query(Guard::learner($this->by), 'topics')->where('id', $outer->id)->update(['status_by' => 'student']);
        $this->assertSame('the student (may have been an accepted AI proposal)', $setBy());

        // tutor (in-app vs over MCP)
        LearnerTables::query(Guard::learner($this->by), 'topics')->where('id', $outer->id)->update(['status' => null, 'status_by' => null, 'status_at' => null]);
        $topics->mark($this->by, $outer->id, 'confused');
        $this->assertSame('you', $setBy());
        $this->assertSame("ViStud's tutor", $setBy($mcp));

        // chat (connected chat app over MCP)
        $topics->mark($mcp, $outer->id, 'covered');
        $this->assertSame('a chat app', $setBy());
        $this->assertSame('a chat app', $setBy($mcp));

        $briefing = app(Briefings::class)->forSession($this->by, $this->context->sessionId)->markdown;
        $this->assertStringContainsString('A chat app marked it', $briefing);
    }

    public function test_ambiguous_join_changes_nothing_and_lists_candidates_while_reads_accept_unique_partials_and_mcp_is_strict(): void
    {
        $topics = app(Topics::class);
        $outer = collect($topics->list($this->by, $this->course))->firstWhere('name', 'Outer joins');
        $inner = $topics->create($this->by, $this->course, 'Inner joins', $this->week2);
        $subqueries = $topics->create($this->by, $this->course, 'Correlated subqueries', $this->week2);

        // "rename join" with Outer joins and Inner joins changes nothing and lists both with ids.
        $renameOut = $this->tool('rename_topic', ['topic' => 'join', 'new_name' => 'All joins']);
        $this->assertStringContainsString("(id {$outer->id})", $renameOut);
        $this->assertStringContainsString("(id {$inner->id})", $renameOut);
        $this->assertSame(['Outer joins', 'Inner joins'], [$topics->find($this->by, $outer->id)->name, $topics->find($this->by, $inner->id)->name]);

        // Reads still accept a unique partial everywhere.
        $mcp = $this->principal(User::findOrFail($this->by->userId), channel: 'mcp');
        $questionsOut = app(Toolbox::class)->run($mcp, $this->context, 'questions', ['topic' => 'subquer']);
        $this->assertSame('No open questions there.', $questionsOut);

        // record_quiz with ambiguous "join" saves nothing for that answer and lists both candidates with ids.
        $quizOut = $this->tool('record_quiz', ['kind' => 'test', 'questions' => [
            ['asked' => 'What is a join?', 'answer' => 'Combines rows', 'result' => 'correct', 'topic' => 'join'],
        ]]);
        $this->assertStringContainsString('Several topics match "join"', $quizOut);
        $this->assertStringContainsString("(id {$outer->id})", $quizOut);
        $this->assertStringContainsString("(id {$inner->id})", $quizOut);
        $this->assertNull($topics->find($this->by, $outer->id)->status);
        $this->assertNull($topics->find($this->by, $inner->id)->status);

        // Over MCP, record_quiz refuses 'Outer join' (no core fallback or partial) and write tools refuse partial names.
        $mcpQuizOut = app(Toolbox::class)->run($mcp, $this->context, 'record_quiz', ['kind' => 'test', 'questions' => [
            ['asked' => 'What does a left outer join keep?', 'answer' => 'Left rows', 'result' => 'correct', 'topic' => 'Outer join'],
        ]]);
        $this->assertStringContainsString('There is no topic called "Outer join"', $mcpQuizOut);
        $this->assertStringContainsString("(id {$outer->id})", $mcpQuizOut);
        $this->assertNull($topics->find($this->by, $outer->id)->status);

        $mcpStatusOut = app(Toolbox::class)->run($mcp, $this->context, 'set_topic_status', ['topic' => 'subquer', 'status' => 'covered', 'reason' => 'Done.']);
        $this->assertStringContainsString('Say its exact name or its id.', $mcpStatusOut);
        $this->assertNull($topics->find($this->by, $subqueries->id)->status);
    }
}
