<?php

namespace Tests\Unit\Brain\Projection;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Brain\Projection\Support\ProjectionAssertions;
use Tests\Unit\Brain\Projection\Support\Scenario;

/**
 * ADR 0002 §5 splits_into: each piece of the split entity's evidence counts
 * towards the entity its assignment names. Assignments name evidence by
 * reference: an attempt, exposure, ask or self-report ("event:…"), or a
 * verdict or match claim ("claim:…"), the more specific winning.
 */
class SplitRulesTest extends TestCase
{
    use ProjectionAssertions;

    private function splitTopic(array $assignments): Scenario
    {
        return Scenario::make()->topic('JOINS')->task('TK-1')->task('TK-2')->exercises('TK-1', 'JOINS')->exercises('TK-2', 'JOINS')
            ->exposure('LECTURE', '2026-10-01 09:00', ['topic:JOINS'], ['session' => 'S0'])
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-02 10:00', 'TK-2', 'incorrect', 'auto', ['session' => 'S2'])
            ->selfReport('SR', '2026-10-02 11:00', 'confused', ['topic:JOINS'], 'S2')
            ->topic('INNER', '2026-10-03 09:00')->topic('OUTER', '2026-10-03 09:00')
            ->splitsInto('SPLIT', 'topic', 'JOINS', ['INNER', 'OUTER'], $assignments, '2026-10-03 09:00');
    }

    public function test_attempts_teaching_and_self_reports_follow_their_assignments(): void
    {
        $p = $this->splitTopic([
            'event:LECTURE' => 'INNER',
            'event:A1' => 'INNER',
            'event:A2' => 'OUTER',
            'event:SR' => 'OUTER',
        ])->project('2026-10-04 10:00');

        $this->assertSame(['INNER' => 'correct'], $p['attempts']['A1']['topics']);
        $this->assertSame(['OUTER' => 'incorrect'], $p['attempts']['A2']['topics']);
        $this->assertTopic($p, 'INNER', 'working');
        $this->assertTopic($p, 'OUTER', 'developing');
    }

    public function test_a_verdict_can_be_assigned_apart_from_the_attempt_it_judges(): void
    {
        $p = $this->splitTopic(['event:A1' => 'INNER', 'event:A2' => 'INNER', 'claim:J2' => 'OUTER'])
            ->judges('J2', 'A2', ['topics' => [['topic' => 'topic:JOINS', 'outcome' => 'incorrect']]], '2026-10-02 10:30')
            ->project('2026-10-04 10:00');

        // A2's exercised topic went to INNER; the verdict naming JOINS went to OUTER.
        $this->assertSame('incorrect', $p['attempts']['A2']['topics']['OUTER']);
        $this->assertArrayHasKey('INNER', $p['attempts']['A2']['topics']);
    }

    public function test_unassigned_evidence_stays_with_the_split_entity(): void
    {
        $p = $this->splitTopic(['event:A1' => 'INNER'])->project('2026-10-04 10:00');

        $this->assertSame(['JOINS' => 'incorrect'], $p['attempts']['A2']['topics']);
        $this->assertTopic($p, 'JOINS', 'developing');
    }

    public function test_a_split_question_assigns_each_ask_and_is_flagged(): void
    {
        $p = Scenario::make()
            ->ask('ASK1', '2026-10-01 19:00', [], 'S1')->ask('ASK2', '2026-10-02 19:00', [], 'S2')
            ->question('DEF', 'Q', '2026-10-01 19:00')
            ->refersTo('R1', 'ASK1', 'Q', '2026-10-01 19:00')->refersTo('R2', 'ASK2', 'Q', '2026-10-02 19:00')
            ->question('DEF-A', 'QA', '2026-10-03 10:00')->question('DEF-B', 'QB', '2026-10-03 10:00')
            ->exposure('ANSWER', '2026-10-02 19:05', [], ['by' => 'chat_ai', 'responds_to' => 'ASK2', 'session' => 'S2'])
            ->splitsInto('SPLIT', 'question', 'Q', ['QA', 'QB'], ['event:ASK1' => 'QA', 'claim:R2' => 'QB'], '2026-10-03 10:00')
            ->project('2026-10-03 10:01');

        $this->assertQuestion($p, 'QA', 'open');
        $this->assertQuestion($p, 'QB', 'being_answered');
        $this->assertSame(['split'], $p['questions']['Q']['flags']);
    }

    public function test_a_split_misconception_assigns_each_exhibit(): void
    {
        $exhibit = fn (string $m) => ['misconceptions' => [['misconception' => "misconception:{$m}", 'present' => true]]];
        $p = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->exercises('TK-1', 'T')->exercises('TK-2', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'incorrect', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-02 10:00', 'TK-2', 'incorrect', 'auto', ['session' => 'S2'])
            ->misconception('DEF', 'M', ['T'], '2026-10-02 11:00')
            ->judges('J1', 'A1', $exhibit('M'), '2026-10-02 11:00')->judges('J2', 'A2', $exhibit('M'), '2026-10-02 11:00')
            ->misconception('DEF-X', 'MX', ['T'], '2026-10-03 10:00')->misconception('DEF-Y', 'MY', ['T'], '2026-10-03 10:00')
            ->splitsInto('SPLIT', 'misconception', 'M', ['MX', 'MY'], ['claim:J1' => 'MX', 'claim:J2' => 'MY'], '2026-10-03 10:00')
            ->project('2026-10-04 10:00');

        $this->assertMisconception($p, 'MX', 'detected');
        $this->assertMisconception($p, 'MY', 'detected');
        $this->assertMisconception($p, 'M', null);
    }

    public function test_a_split_task_gives_each_attempt_its_own_task(): void
    {
        $p = Scenario::make()->topic('T')->task('TK')->exercises('TK', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-01 10:30', 'TK', 'correct', 'auto', ['session' => 'S1'])
            ->task('TK-A', '2026-10-02 10:00')->task('TK-B', '2026-10-02 10:00')->exercises('TK-A', 'T')->exercises('TK-B', 'T')
            ->splitsInto('SPLIT', 'task', 'TK', ['TK-A', 'TK-B'], ['event:A1' => 'TK-A', 'event:A2' => 'TK-B'], '2026-10-02 10:00')
            ->project('2026-10-03 10:00');

        $this->assertSame(['TK-A', 'TK-B'], [$p['attempts']['A1']['task'], $p['attempts']['A2']['task']]);
        $this->assertSame('none', $p['attempts']['A2']['repeat'], 'Two different tasks now, so not a repeat');
        $this->assertSame(2, $p['topics']['T']['facts']['apply_tasks']);
    }
}
