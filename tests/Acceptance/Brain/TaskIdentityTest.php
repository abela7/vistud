<?php

namespace Tests\Acceptance\Brain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Acceptance\Brain\Entries as E;

/** Task identity edge cases X1–X5 of docs/specs/golden-replay-sql-joins.md, on topic T-LEFT. */
class TaskIdentityTest extends TestCase
{
    /** Local time on day $day of January 2027 (GMT, so +00:00). Day 32 is 1 Feb. */
    private static function day(int $day, string $time = '10:00'): string
    {
        return (new DateTimeImmutable("2026-12-31T{$time}:00+00:00"))->modify("+{$day} days")->format('Y-m-d\TH:i:sP');
    }

    /** Topic T-LEFT, and tasks that exercise it. */
    private static function topicAndTasks(string ...$tasks): array
    {
        $at = '2026-12-31T09:00:00+00:00';
        $specs = [E::topic('K-LEFT', 'T-LEFT', $at)];
        foreach ($tasks as $task) {
            $specs[] = E::task($task, "lab/{$task}", $at);
            $specs[] = E::relates("X-{$task}", $at, "task:{$task}", 'exercises', 'topic:T-LEFT');
        }

        return $specs;
    }

    /** A correct explanation in the learner's own words, judged by the chat AI and the interpreter. */
    private static function explanation(string $id, int $day, string $task): array
    {
        return [
            E::attempt($id, self::day($day), "S{$day}", $task, 'correct', 'ai', 'explain', 'chat', actor: E::CHATGPT),
            E::judgesAttempt("J-{$id}", self::day($day, '10:30'), $id, ['overall' => 'correct', 'own_words' => true]),
        ];
    }

    private static function left(InMemoryJournal $journal, string $now = '2027-03-01T12:00:00+00:00'): array
    {
        return $journal->project($now)['topics']['T-LEFT'];
    }

    /** X1: a delayed repeat of the same task adds no diversity; a second task on day 5 does. */
    public function test_x1_the_same_task_twice_is_one_apply_task(): void
    {
        $journal = InMemoryJournal::of([
            ...self::topicAndTasks('TK-1', 'TK-2', 'TK-E'),
            E::attempt('A1', self::day(1), 'S1', 'TK-1', 'correct'),
            ...self::explanation('A2', 2, 'TK-E'),
            E::attempt('A3', self::day(3), 'S3', 'TK-1', 'correct'),
        ]);

        $this->assertSame('delayed', $journal->project(self::day(3, '12:00'))['attempts']['A3']['repeat']);
        $this->assertSame('working', self::left($journal, self::day(3, '12:00'))['label']);

        $journal->append(E::attempt('A4', self::day(5), 'S5', 'TK-2', 'correct'));
        $this->assertSame('secure', self::left($journal, self::day(5, '12:00'))['label']);
    }

    /** X2: failed, taught, then right 20 minutes later: an immediate retry, so still developing. */
    public function test_x2_an_immediate_retry_after_teaching_counts_for_nothing(): void
    {
        $journal = InMemoryJournal::of([
            ...self::topicAndTasks('TK-1'),
            E::attempt('A1', self::day(1, '10:00'), 'S1', 'TK-1', 'incorrect'),
            E::exposure('T1', self::day(1, '10:05'), 'S1', 'feedback', ['T-LEFT'], ['type' => 'chat_ai', 'id' => 'chatgpt'], ['contrast'], actor: E::CHATGPT),
            E::attempt('A2', self::day(1, '10:20'), 'S1', 'TK-1', 'correct'),
        ]);

        $this->assertSame('immediate', $journal->project(self::day(1, '12:00'))['attempts']['A2']['repeat']);
        $this->assertSame('developing', self::left($journal, self::day(1, '12:00'))['label']);
    }

    private static function regression(): array
    {
        return [
            ...self::topicAndTasks('TK-1', 'TK-2'),
            E::attempt('A1', self::day(1), 'S1', 'TK-1', 'correct'),
            E::attempt('A2', self::day(30), 'S30', 'TK-2', 'incorrect'),
        ];
    }

    /** X3: after a regression and teaching, a delayed repeat of the failed task recovers the topic to working. */
    public function test_x3_a_delayed_repeat_recovers_a_regressed_topic(): void
    {
        $journal = InMemoryJournal::of(self::regression());
        $this->assertSame(['developing', ['regressed']], self::labelAndRegressed(self::left($journal, self::day(30, '12:00'))));

        $journal->append(E::exposure('T1', self::day(31), 'S31', 'feedback', ['T-LEFT'], ['type' => 'chat_ai', 'id' => 'chatgpt'], ['contrast'], actor: E::CHATGPT));
        $journal->append(E::attempt('A3', self::day(33), 'S33', 'TK-2', 'correct'));

        $this->assertSame('delayed', $journal->project(self::day(33, '12:00'))['attempts']['A3']['repeat']);
        $this->assertSame(['working', []], self::labelAndRegressed(self::left($journal, self::day(33, '12:00'))));
    }

    /** X3, by contrast: an immediate retry on day 30 does not recover it. */
    public function test_x3_an_immediate_retry_does_not_recover_a_regressed_topic(): void
    {
        $journal = InMemoryJournal::of([...self::regression(), E::attempt('A3', self::day(30, '10:20'), 'S30', 'TK-2', 'correct')]);

        $this->assertSame(['developing', ['regressed']], self::labelAndRegressed(self::left($journal, self::day(30, '12:00'))));
    }

    /** X4: the same task on day 1 and day 25, nothing in between: the day-25 success is retained. */
    public function test_x4_a_success_after_a_long_gap_is_retained(): void
    {
        $journal = InMemoryJournal::of([
            ...self::topicAndTasks('TK-1'),
            E::attempt('A1', self::day(1), 'S1', 'TK-1', 'correct'),
            E::attempt('A2', self::day(25), 'S25', 'TK-1', 'correct'),
        ]);

        $this->assertTrue(self::left($journal, self::day(25, '12:00'))['facts']['retained']);
    }

    /** X4, by contrast: weekly successes on days 1, 8, 15 and 22 are practised, never retained. */
    public function test_x4_weekly_practice_is_practised_never_retained(): void
    {
        $specs = self::topicAndTasks('TK-1');
        foreach ([1, 8, 15, 22] as $day) {
            $specs[] = E::attempt("A{$day}", self::day($day), "S{$day}", 'TK-1', 'correct');
        }
        $topic = self::left(InMemoryJournal::of($specs), self::day(22, '12:00'));

        $this->assertContains('practised', $topic['flags']);
        $this->assertFalse($topic['facts']['retained']);
    }

    private static function mergeable(): array
    {
        return [
            ...self::topicAndTasks('TK-1', 'TK-2', 'TK-E'),
            E::claim('SA1', self::day(1, '09:00'), 'same_as', ['task:TK-1', 'task:TK-2'], ['survivor' => 'task:TK-2'], E::rule('task-resolver'), 0.95),
            E::attempt('A1', self::day(1), 'S1', 'TK-1', 'correct'),
            ...self::explanation('A2', 2, 'TK-E'),
            E::attempt('A3', self::day(3), 'S3', 'TK-2', 'correct'),
        ];
    }

    /** X5: attempts on two merged task records count as one task. */
    public function test_x5_merged_tasks_count_as_one(): void
    {
        $journal = InMemoryJournal::of(self::mergeable());

        $this->assertSame('working', self::left($journal, self::day(3, '12:00'))['label']);
        $this->assertSame(1, self::left($journal, self::day(3, '12:00'))['facts']['apply_tasks']);
    }

    /** X5, by contrast: once the merge is rejected they count as two. */
    public function test_x5_a_rejected_merge_counts_two_tasks(): void
    {
        $journal = InMemoryJournal::of([...self::mergeable(), E::review('R1', self::day(3, '11:00'), 'claim:SA1', 'reject', 'not_same')]);

        $this->assertSame(2, self::left($journal, self::day(3, '12:00'))['facts']['apply_tasks']);
        $this->assertSame('secure', self::left($journal, self::day(3, '12:00'))['label']);
    }

    private static function labelAndRegressed(array $topic): array
    {
        return [$topic['label'], array_values(array_intersect($topic['flags'], ['regressed']))];
    }
}
