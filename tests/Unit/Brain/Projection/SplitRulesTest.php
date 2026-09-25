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

    private function splitTopic(array $assignments, ?callable $beforeSplit = null): Scenario
    {
        $s = Scenario::make()->topic('JOINS')->task('TK-1')->task('TK-2')->exercises('TK-1', 'JOINS')->exercises('TK-2', 'JOINS')
            ->exposure('LECTURE', '2026-10-01 09:00', ['topic:JOINS'], ['session' => 'S0'])
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-02 10:00', 'TK-2', 'incorrect', 'auto', ['session' => 'S2'])
            ->selfReport('SR', '2026-10-02 11:00', 'confused', ['topic:JOINS'], 'S2')
            ->topic('INNER', '2026-10-03 09:00')->topic('OUTER', '2026-10-03 09:00');
        if ($beforeSplit !== null) {
            $beforeSplit($s);
        }

        return $s->splitsInto('SPLIT', 'topic', 'JOINS', ['INNER', 'OUTER'], $assignments, '2026-10-03 09:00');
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
        $p = $this->splitTopic(
            ['event:LECTURE' => 'INNER', 'event:A1' => 'INNER', 'event:A2' => 'INNER', 'event:SR' => 'OUTER', 'claim:J2' => 'OUTER'],
            fn (Scenario $s) => $s->judges('J2', 'A2', ['topics' => [['topic' => 'topic:JOINS', 'outcome' => 'incorrect']]], '2026-10-02 10:30'),
        )->project('2026-10-04 10:00');

        // A2's exercised topic went to INNER; the verdict naming JOINS went to OUTER.
        $this->assertSame('incorrect', $p['attempts']['A2']['topics']['OUTER']);
        $this->assertArrayHasKey('INNER', $p['attempts']['A2']['topics']);
    }

    public function test_an_incomplete_split_does_not_apply_and_is_reported(): void
    {
        // PM clarification (WP4 review): an incomplete split never becomes a
        // partially applied one; the previous interpretation stays in force.
        $p = $this->splitTopic(['event:A1' => 'INNER'])->project('2026-10-04 10:00');

        $this->assertSame(['JOINS' => 'correct'], $p['attempts']['A1']['topics']);
        $this->assertSame(['JOINS' => 'incorrect'], $p['attempts']['A2']['topics']);
        $this->assertTopic($p, 'JOINS', 'developing');
        $this->assertSame([[
            'claim' => 'SPLIT',
            'entity' => 'topic:JOINS',
            'problems' => ['unassigned_evidence'],
            'unassigned' => ['event:A2', 'event:LECTURE', 'event:SR'],
        ]], $p['invalid_splits']);
    }

    public function test_assignments_must_go_to_defined_parts_and_name_real_evidence(): void
    {
        $complete = ['event:LECTURE' => 'INNER', 'event:A1' => 'INNER', 'event:A2' => 'OUTER', 'event:SR' => 'OUTER'];

        $p = $this->splitTopic($complete + ['event:NOT-EVIDENCE' => 'INNER'])->project('2026-10-04 10:00');
        $this->assertSame(['assignment_not_evidence'], $p['invalid_splits'][0]['problems']);

        $p = $this->splitTopic(array_replace($complete, ['event:A2' => 'ELSEWHERE']))->project('2026-10-04 10:00');
        $this->assertSame(['assignment_outside_parts'], $p['invalid_splits'][0]['problems']);

        $p = Scenario::make()->topic('A')
            ->splitsInto('SPLIT', 'topic', 'A', ['UNDEFINED'], [], '2026-10-03 09:00')
            ->project('2026-10-04 10:00');
        $this->assertSame(['part_not_defined'], $p['invalid_splits'][0]['problems']);
    }

    public function test_completeness_counts_evidence_recorded_before_the_split_took_effect(): void
    {
        // Proposed on 3 Oct (pending), accepted on 5 Oct: A3, recorded in
        // between, must be assigned too.
        $s = $this->splitTopic(['event:LECTURE' => 'INNER', 'event:A1' => 'INNER', 'event:A2' => 'OUTER', 'event:SR' => 'OUTER']);
        $specs = $s->specs();
        $specs[array_key_last($specs)]['body']['review']['state'] = 'pending';
        $pending = Scenario::make();
        foreach ($specs as $spec) {
            $pending->add($spec);
        }
        $pending->attempt('A3', '2026-10-04 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S3'])
            ->learnerReview('YES', 'claim:SPLIT', 'accept', '2026-10-05 10:00');

        $p = $pending->project('2026-10-06 10:00');
        $this->assertSame(['event:A3'], $p['invalid_splits'][0]['unassigned']);

        // Evidence recorded after the split took effect stays with the split entity.
        $s->attempt('LATER', '2026-10-06 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S4']);
        $p = $s->project('2026-10-07 10:00');
        $this->assertSame([], $p['invalid_splits']);
        $this->assertSame(['JOINS' => 'correct'], $p['attempts']['LATER']['topics']);
    }

    public function test_a_later_split_sees_the_evidence_an_earlier_split_assigned(): void
    {
        $s = $this->splitTopic(['event:LECTURE' => 'INNER', 'event:A1' => 'INNER', 'event:A2' => 'OUTER', 'event:SR' => 'OUTER'])
            ->topic('LEFT', '2026-10-04 09:00')->topic('RIGHT', '2026-10-04 09:00');

        $incomplete = (clone $s)->splitsInto('SPLIT2', 'topic', 'OUTER', ['LEFT', 'RIGHT'], ['event:A2' => 'LEFT'], '2026-10-04 09:00');
        $this->assertSame(['event:SR'], $incomplete->project('2026-10-05 10:00')['invalid_splits'][0]['unassigned']);

        $p = $s->splitsInto('SPLIT2', 'topic', 'OUTER', ['LEFT', 'RIGHT'], ['event:A2' => 'LEFT', 'event:SR' => 'RIGHT'], '2026-10-04 09:00')
            ->project('2026-10-05 10:00');
        $this->assertSame([], $p['invalid_splits']);
        $this->assertSame(['LEFT' => 'incorrect'], $p['attempts']['A2']['topics']);
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
