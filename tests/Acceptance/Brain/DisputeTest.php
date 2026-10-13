<?php

namespace Tests\Acceptance\Brain;

use PHPUnit\Framework\TestCase;
use Tests\Acceptance\Brain\Entries as E;

/** Dispute edge cases D1–D6 of docs/specs/golden-replay-sql-joins.md, on topic T-LEFT. */
class DisputeTest extends TestCase
{
    private const NOW = '2026-11-30T12:00:00+00:00';

    private static function at(string $time, int $day = 2): string
    {
        return sprintf('2026-11-%02dT%s:00+00:00', $day, $time);
    }

    private static function topicAndTasks(string ...$tasks): array
    {
        $at = '2026-11-01T09:00:00+00:00';
        $specs = [E::topic('K-LEFT', 'T-LEFT', $at)];
        foreach ($tasks as $task) {
            $specs[] = E::task($task, "lab/{$task}", $at);
            $specs[] = E::relates("X-{$task}", $at, "task:{$task}", 'exercises', 'topic:T-LEFT');
        }

        return $specs;
    }

    /** The chat AI records A1 as correct; the interpreter's J1 judges it incorrect. */
    private static function contested(): InMemoryJournal
    {
        return InMemoryJournal::of([
            ...self::topicAndTasks('TK-1'),
            E::attempt('A1', self::at('10:00'), 'S1', 'TK-1', 'correct', 'ai', setting: 'chat', actor: E::CHATGPT),
            E::judgesAttempt('J1', self::at('10:30'), 'A1', ['overall' => 'incorrect']),
        ]);
    }

    private static function outcome(InMemoryJournal $journal, string $attempt = 'A1'): array
    {
        $snapshot = $journal->project(self::NOW);

        return [$snapshot['attempts'][$attempt]['overall'], $snapshot['attempts'][$attempt]['topics']['T-LEFT'], $snapshot['topics']['T-LEFT']['label']];
    }

    /** D1: the writer refuses the learner's rejection of a verdict on their work; the projection ignores one that got in. */
    public function test_d1_the_learner_cannot_reject_a_verdict_on_their_work(): void
    {
        $journal = self::contested();
        $reject = E::review('R1', self::at('11:00'), 'claim:J1', 'reject');

        $refusal = $journal->refusal($reject);
        $this->assertNotNull($refusal);
        $this->assertSame('dispute', $refusal['use']);

        $journal->force($reject);
        $this->assertSame(['incorrect', 'incorrect', 'developing'], self::outcome($journal));
    }

    /** D2: disputing the interpreter suspends the outcome; the chat AI's "correct" is not restored. */
    public function test_d2_a_dispute_suspends_the_verdict_without_restoring_the_lower_one(): void
    {
        $journal = self::contested();
        $journal->append(E::review('D1', self::at('11:00'), 'claim:J1', 'dispute'));

        $this->assertSame(['disputed', 'disputed', 'developing'], self::outcome($journal));
    }

    /** D3: withdrawing the dispute brings back the incorrect outcome. */
    public function test_d3_withdrawing_the_dispute(): void
    {
        $journal = self::contested();
        $journal->append(E::review('D1', self::at('11:00'), 'claim:J1', 'dispute'));
        $journal->append(E::review('W1', self::at('11:30'), 'claim:D1', 'withdraw', 'other'));

        $this->assertSame(['incorrect', 'incorrect', 'developing'], self::outcome($journal));
    }

    /** D4: only a verdict that cites the dispute, with at least the interpreter's authority, settles it. */
    public function test_d4_only_a_citing_verdict_of_enough_authority_settles_a_dispute(): void
    {
        $journal = self::contested();
        $journal->append(E::review('D1', self::at('11:00'), 'claim:J1', 'dispute'));
        $journal->append(E::review('W1', self::at('11:30'), 'claim:D1', 'withdraw', 'other'));
        $journal->append(E::review('D2', self::at('12:00'), 'claim:J1', 'dispute'));

        $journal->append(E::judgesAttempt('J2', self::at('12:30'), 'A1', ['overall' => 'correct']));
        $journal->append(E::judgesAttempt('J3', self::at('12:40'), 'A1', ['overall' => 'correct'], 0.9, ['derived_from' => ['claim:D2']], E::chatAi()));
        $this->assertSame(['disputed', 'disputed', 'developing'], self::outcome($journal));

        $journal->append(E::judgesAttempt('J4', self::at('12:50'), 'A1', ['overall' => 'correct'], extra: ['derived_from' => ['claim:D2']]));
        $this->assertSame(['correct', 'correct', 'working'], self::outcome($journal));
    }

    /** D5: a disputed auto outcome is settled only by re-running the checker, not by the interpreter. */
    public function test_d5_a_disputed_auto_outcome_needs_the_checker_again(): void
    {
        $journal = InMemoryJournal::of([
            ...self::topicAndTasks('TK-1'),
            E::attempt('A1', self::at('10:00'), 'S1', 'TK-1', 'incorrect'),
            E::review('D1', self::at('11:00'), 'event:A1', 'dispute'),
            E::judgesAttempt('J1', self::at('11:30'), 'A1', ['overall' => 'correct'], extra: ['derived_from' => ['claim:D1']]),
        ]);
        $this->assertSame(['disputed', 'disputed', 'developing'], self::outcome($journal));

        $journal->append(E::claim('J2', self::at('12:00'), 'judges', ['event:A1'], ['overall' => 'correct'], E::auto(), null, 'accepted',
            ['derived_from' => ['claim:D1']], ['type' => 'processor', 'id' => 'lab-checker', 'channel' => 'job']));
        $this->assertSame(['correct', 'correct', 'working'], self::outcome($journal));
    }

    /**
     * D6: disputing an active misconception's definition lets a blocked
     * success count, flagged rests_on_dispute, until a new exhibit on an
     * attempt made after the dispute.
     */
    public function test_d6_a_disputed_misconception_stops_blocking_until_it_is_exhibited_again(): void
    {
        $exhibit = ['overall' => 'incorrect', 'misconceptions' => E::misconception('M1', true), 'cause' => 'misconception'];
        $journal = InMemoryJournal::of([
            ...self::topicAndTasks('TK-1', 'TK-2', 'TK-3', 'TK-4'),
            E::attempt('A1', self::at('10:00'), 'S1', 'TK-1', 'incorrect'),
            E::attempt('A2', self::at('10:10'), 'S1', 'TK-2', 'incorrect'),
            E::claim('M1-DEF', self::at('10:30'), 'defines', ['misconception:M1'], ['entity_type' => 'misconception', 'status' => 'active', 'topics' => ['topic:T-LEFT']],
                E::interpreter('diagnose@1'), 0.8) + ['content' => ['statement' => 'Filters the outer table in WHERE.']],
            E::judgesAttempt('J1', self::at('10:30'), 'A1', $exhibit),
            E::judgesAttempt('J2', self::at('10:30'), 'A2', $exhibit),
            E::attempt('A3', self::at('10:00', 3), 'S2', 'TK-3', 'correct'),
        ]);
        $snapshot = $journal->project(self::NOW);
        $this->assertSame('recurring', $snapshot['misconceptions']['M1']['state']);
        $this->assertSame('developing', $snapshot['topics']['T-LEFT']['label']);

        $journal->append(E::review('D1', self::at('11:00', 3), 'claim:M1-DEF', 'dispute', 'wrong_diagnosis'));
        $snapshot = $journal->project(self::NOW);
        $this->assertSame('disputed', $snapshot['misconceptions']['M1']['state']);
        $this->assertSame('working', $snapshot['topics']['T-LEFT']['label']);
        $this->assertContains('rests_on_dispute', $snapshot['topics']['T-LEFT']['flags']);

        $journal->append(E::attempt('A4', self::at('10:00', 4), 'S3', 'TK-4', 'incorrect'));
        $journal->append(E::judgesAttempt('J4', self::at('10:30', 4), 'A4', $exhibit));
        $snapshot = $journal->project(self::NOW);
        $this->assertContains($snapshot['misconceptions']['M1']['state'], ['detected', 'recurring', 'resurfaced', 'addressed']);
        $this->assertSame('developing', $snapshot['topics']['T-LEFT']['label']);
    }
}
