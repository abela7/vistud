<?php

namespace Tests\Feature\Study;

use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Study\CourseRoll;
use App\Study\Flashcards;
use App\Study\ModuleRoll;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Quizzes;
use App\Study\Rollups;
use App\Study\Sessions;
use App\Study\TopicDetails;
use App\Study\TopicRoll;
use App\Study\Topics;
use App\Study\TopicSuggestions;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** How progress adds up, topic to module to course, and what needs attention (docs/specs/vistud-2-blueprint.md §3.8). */
class RollupsTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private Topics $topics;

    private Rollups $rollups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->topics = app(Topics::class);
        $this->rollups = app(Rollups::class);
    }

    private function module(string $title, ?string $startsOn = null, ?string $endsOn = null): string
    {
        return app(Modules::class)->create($this->by, $this->workspace, ['title' => $title, 'starts_on' => $startsOn, 'ends_on' => $endsOn])->id;
    }

    private function topic(string $name, ?string $module = null, ?string $status = null): string
    {
        $id = $this->topics->create($this->by, $this->workspace, $name, $module)->id;
        if ($status !== null) {
            $this->topics->report($this->by, $id, $status);
        }

        return $id;
    }

    private function roll(): CourseRoll
    {
        return $this->rollups->for($this->by, $this->workspace);
    }

    private function of(string $topicId): TopicRoll
    {
        return collect($this->roll()->topics())->firstWhere(fn (TopicRoll $topic) => $topic->id() === $topicId);
    }

    public function test_a_course_is_the_sum_of_its_modules_so_a_small_module_weighs_what_its_topics_do(): void
    {
        $small = $this->module('Intro');
        $big = $this->module('Memory');
        $this->topic('What an OS is', $small, 'understood');
        $this->topic('History', $small, 'understood');
        foreach (range(1, 10) as $n) {
            $this->topic("Memory {$n}", $big);
        }

        $roll = $this->roll();
        [$first, $second] = $roll->modules;
        $this->assertSame([2, 2, 100], [$first->total(), $first->done(), $first->percent()]);
        $this->assertSame([10, 0, 0], [$second->total(), $second->done(), $second->percent()]);
        // 2 of 12, not the 50 % an average of the two modules would say.
        $this->assertSame([12, 2, 17], [$roll->total(), $roll->done(), $roll->percent()]);
        $this->assertSame([1, 2], [$first->number, $second->number]);
    }

    public function test_understood_and_mastered_count_and_covered_confusing_and_not_started_do_not(): void
    {
        $module = $this->module('Scheduling');
        $this->topic('Quantum', $module, 'covered');
        $this->topic('Policies', $module, 'understood');
        $this->topic('Aging', $module, 'confused');
        $this->topic('Priorities', $module);

        $roll = $this->roll();
        $this->assertSame(1, $roll->done());
        $this->assertSame(['covered', 'understood', 'confused', 'not_started'], array_map(fn (TopicRoll $topic) => $topic->shown, $roll->topics()));
        $this->assertSame(1, $roll->notStarted());

        // Mastery is earned from the evidence: it counts as understood.
        $details = fn (string $label) => new TopicDetails('t', 'w', 'm', 'Topic', null, 1, $label, [], null);
        $group = new ModuleRoll(null, 0, [new TopicRoll($details('secure'), 'mastered'), new TopicRoll($details('working'), 'covered')]);
        $this->assertSame([2, 1, 50], [$group->total(), $group->done(), $group->percent()]);
    }

    public function test_topics_in_no_module_are_a_group_of_their_own_and_count_in_the_course(): void
    {
        $module = $this->module('Scheduling');
        $this->topic('Quantum', $module, 'understood');
        $loose = $this->topic('Spooling', null, 'understood');
        $this->topic('Buffers');

        $roll = $this->roll();
        $this->assertCount(1, $roll->modules);
        $this->assertNotNull($roll->unplaced);
        $this->assertSame(['', 'No module', 0, 2, 1], [$roll->unplaced->id(), $roll->unplaced->title(), $roll->unplaced->number, $roll->unplaced->total(), $roll->unplaced->done()]);
        $this->assertCount(2, $roll->groups());
        $this->assertSame([3, 2], [$roll->total(), $roll->done()]);
        $this->assertTrue($this->of($loose)->understood());
    }

    public function test_a_course_with_no_topics_is_empty_and_has_no_group_for_none(): void
    {
        $this->module('Scheduling');
        $roll = $this->roll();

        $this->assertSame([0, 0, 0], [$roll->total(), $roll->done(), $roll->percent()]);
        $this->assertNull($roll->unplaced);
        $this->assertSame([], $roll->attention);
    }

    public function test_a_topic_confusing_for_a_week_needs_attention_and_a_newer_one_does_not(): void
    {
        $old = $this->topic('Deadlocks', null, 'confused');
        $this->travel(6)->days();
        $six = $this->topic('Paging', null, 'confused');
        $this->assertSame([], $this->roll()->attention, 'six days is not yet a week');

        $this->travel(1)->days();
        $roll = $this->roll();
        $this->assertSame([$old], array_map(fn (TopicRoll $topic) => $topic->id(), $roll->attention));
        $this->assertSame(['confusing'], $this->of($old)->reasons);
        $this->assertSame('2026-10-14', $this->of($old)->since);
        $this->assertSame([], $this->of($six)->reasons);

        $this->travel(6)->days();
        $this->assertCount(2, $this->roll()->attention, 'a week is enough');
        // The student changing their mind clears it.
        $this->topics->report($this->by, $old, 'understood');
        $this->assertSame([$six], array_map(fn (TopicRoll $topic) => $topic->id(), $this->roll()->attention));
    }

    public function test_a_stuck_question_about_a_topic_needs_attention_until_it_is_answered(): void
    {
        $topic = $this->topic('Semaphores');
        $questions = app(Questions::class);
        $question = $questions->ask($this->by, $this->workspace, 'Why can a semaphore deadlock?', $topic)->id;
        $loose = $questions->ask($this->by, $this->workspace, 'A question about nothing in particular')->id;
        $this->assertSame([], $this->roll()->attention, 'pending is not stuck');

        $questions->setStatus($this->by, $question, 'stuck');
        $questions->setStatus($this->by, $loose, 'stuck');
        $roll = $this->roll();
        $this->assertSame(['stuck'], $this->of($topic)->reasons);
        $this->assertSame(1, $this->of($topic)->stuckQuestions);
        $this->assertSame(1, $this->of($topic)->openQuestions);
        $this->assertSame(2, $roll->stuck, 'the course counts every stuck question, with a topic or not');
        $this->assertCount(1, $roll->attention);

        $questions->setStatus($this->by, $question, 'answered', 'Because of lock ordering.');
        $this->assertSame([], $this->of($topic)->reasons);
        $this->assertSame(0, $this->of($topic)->openQuestions);
    }

    public function test_a_test_under_half_needs_attention_until_the_student_says_otherwise_or_does_better(): void
    {
        $module = $this->module('Scheduling');
        $topic = $this->topic('Policies', $module);
        $session = app(Sessions::class)->start($this->by, $this->workspace, $topic, null, null, null, 'test')->id;
        $quizzes = app(Quizzes::class);
        $ask = fn (string $result) => ['asked' => 'Q', 'answer' => 'A', 'result' => $result, 'right' => 'R', 'fix' => 'F', 'topic' => 'Policies'];

        $quizzes->record($this->by, $session, ['kind' => 'test', 'questions' => [$ask('incorrect'), $ask('incorrect'), $ask('correct')]]);
        $this->assertSame(['test'], $this->of($topic)->reasons);
        $this->assertSame(33, $this->of($topic)->tested);
        $this->assertSame(33, $this->roll()->modules[0]->tested);
        $this->assertSame('33 % in the test', $this->of($topic)->attentionWords());

        // The student's word after the test is the newer evidence.
        $this->travel(1)->minutes();
        $this->topics->report($this->by, $topic, 'understood');
        $this->assertSame([], $this->of($topic)->reasons);

        // A better test later replaces the score; exactly half is not under half.
        $this->travel(1)->minutes();
        $quizzes->record($this->by, $session, ['kind' => 'test', 'questions' => [$ask('correct'), $ask('incorrect')]]);
        $this->assertSame([50, []], [$this->of($topic)->tested, $this->of($topic)->reasons]);
    }

    public function test_a_module_says_how_its_latest_quiz_or_test_went_and_a_quiz_alone_needs_no_attention(): void
    {
        $module = $this->module('Scheduling');
        $topic = $this->topic('Policies', $module);
        $session = app(Sessions::class)->start($this->by, $this->workspace, $topic, null, null, null, 'quiz')->id;
        app(Quizzes::class)->record($this->by, $session, ['kind' => 'quiz', 'questions' => [['asked' => 'Q', 'answer' => 'A', 'result' => 'incorrect', 'right' => 'R', 'fix' => 'F']]]);

        $roll = $this->roll();
        $this->assertSame(0, $roll->modules[0]->tested);
        $this->assertSame([], $roll->attention);
        $this->assertNull($this->of($topic)->tested);
    }

    public function test_cards_a_week_overdue_need_attention(): void
    {
        $topic = $this->topic('Policies');
        $card = app(Flashcards::class)->add($this->by, $this->workspace, $topic, 'What is a quantum?', 'A slice of time.');
        DB::table('flashcards')->where('id', $card)->update(['due_on' => '2026-10-08']);
        $this->assertSame([], $this->roll()->attention, 'six days late is not a week');

        DB::table('flashcards')->where('id', $card)->update(['due_on' => '2026-10-07']);
        $roll = $this->roll();
        $this->assertSame(['overdue'], $this->of($topic)->reasons);
        $this->assertSame([1, 1, 1], [$this->of($topic)->cards, $this->of($topic)->cardsDue, $this->of($topic)->cardsOverdue]);
        $this->assertSame(['total' => 1, 'due' => 1, 'new' => 0, 'overdue' => 1], $roll->cards);
        $this->assertSame('1 card a week late', $this->of($topic)->attentionWords());
    }

    public function test_attention_is_ordered_most_urgent_first(): void
    {
        $module = $this->module('Scheduling');
        $late = $this->topic('Late cards', $module);
        $stuck = $this->topic('Stuck question', $module);
        $confusing = $this->topic('Confusing', $module, 'confused');
        $this->travel(8)->days();
        $card = app(Flashcards::class)->add($this->by, $this->workspace, $late, 'Q', 'A');
        DB::table('flashcards')->where('id', $card)->update(['due_on' => '2026-10-01']);
        $question = app(Questions::class)->ask($this->by, $this->workspace, 'Stuck?', $stuck)->id;
        app(Questions::class)->setStatus($this->by, $question, 'stuck');

        $roll = $this->roll();
        $this->assertSame([$confusing, $stuck, $late], array_map(fn (TopicRoll $topic) => $topic->id(), $roll->attention));
        $this->assertSame(3, $roll->modules[0]->attention(), 'the module counts the topics that need a look');
    }

    public function test_the_module_the_student_is_in_is_where_they_last_studied_else_the_one_running_today_else_the_first(): void
    {
        $first = $this->module('One');
        $running = $this->module('Two', '2026-10-10', '2026-10-20');
        $third = $this->module('Three');
        $this->assertSame($running, $this->roll()->currentId);

        $session = app(Sessions::class)->start($this->by, $this->workspace, null, $third)->id;
        app(Sessions::class)->end($this->by, $session);
        $this->assertSame($third, $this->roll()->currentId);
        $this->assertSame($session, $this->roll()->last?->id);

        $this->assertSame($first, Rollups::current([app(Modules::class)->find($this->by, $first)], null, '2026-10-14'));
        $this->assertNull(Rollups::current([], null, '2026-10-14'));
    }

    public function test_what_waits_in_a_module_and_its_materials_are_counted(): void
    {
        $module = $this->module('Scheduling');
        app(Notes::class)->create($this->by, 'module', $module, 'Lecture notes');
        app(TopicSuggestions::class)->suggest($this->by, $module, null, ['Quantum', 'Aging']);

        $group = $this->roll()->modules[0];
        $this->assertSame([1, 2], [$group->materials, $group->suggested]);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_size_of_the_course(): void
    {
        $count = function (): int {
            $queries = 0;
            Event::listen(QueryExecuted::class, function () use (&$queries) {
                $queries++;
            });
            $this->roll();

            return $queries;
        };
        $module = $this->module('Scheduling');
        $one = $this->topic('One', $module, 'understood');
        app(Questions::class)->ask($this->by, $this->workspace, 'A question', $one);
        $small = $count();

        foreach (range(1, 6) as $n) {
            $other = $this->module("Module {$n}");
            foreach (range(1, 5) as $m) {
                $topic = $this->topic("Topic {$n}.{$m}", $other, $m === 1 ? 'confused' : null);
                app(Flashcards::class)->add($this->by, $this->workspace, $topic, "Q {$n}.{$m}", 'A');
                app(Questions::class)->ask($this->by, $this->workspace, "Question {$n}.{$m}", $topic);
            }
        }
        $this->assertSame($small, $count(), 'a course ten times the size takes the same number of queries');
    }

    public function test_another_students_course_is_not_there(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private'])->id;

        $this->expectException(NotFound::class);
        $this->rollups->for($this->by, $theirs);
    }
}
