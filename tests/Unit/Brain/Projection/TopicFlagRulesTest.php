<?php

namespace Tests\Unit\Brain\Projection;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Brain\Projection\Support\ProjectionAssertions;
use Tests\Unit\Brain\Projection\Support\Scenario;

/** ADR 0002 §7 topic labels and flags that the other rule tests don't cover. */
class TopicFlagRulesTest extends TestCase
{
    use ProjectionAssertions;

    public function test_introduced_through_a_parent_and_not_started_without_contact(): void
    {
        $p = Scenario::make()->topic('PARENT')->topic('CHILD')->topic('OTHER')->partOf('CHILD', 'PARENT')
            ->exposure('LECTURE', '2026-10-01 10:00', ['topic:PARENT'], ['format' => 'lecture'])
            ->project('2026-10-02 10:00');

        $this->assertTopic($p, 'PARENT', 'introduced');
        $this->assertTopic($p, 'CHILD', 'introduced');
        $this->assertTopic($p, 'OTHER', 'not_started');
    }

    public function test_claimed_only_counts_confidence_about_a_question_on_the_topic(): void
    {
        $p = Scenario::make()->topic('T')
            ->ask('ASK', '2026-10-01 19:00', ['topic:T'])->question('DEF', 'Q', '2026-10-01 19:00')
            ->refersTo('R', 'ASK', 'Q', '2026-10-01 19:00')->stemsFrom('S', 'Q', 'topic:T', '2026-10-01 19:00')
            ->selfReport('GOT-IT', '2026-10-01 19:05', 'clicked', ['question:Q'])
            ->project('2026-10-02 10:00');

        $this->assertHasFlag($p, 'T', 'claimed_only');
    }

    public function test_overconfident_needs_a_failure_after_the_latest_confident_report_about_the_topic(): void
    {
        $s = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->exercises('TK-1', 'T')->exercises('TK-2', 'T')
            ->selfReport('SURE', '2026-10-01 09:00', 'confident', ['topic:T'])
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'incorrect', 'auto', ['session' => 'S1']);
        $this->assertHasFlag($s->project('2026-10-02 10:00'), 'T', 'overconfident');

        $s->attempt('A2', '2026-10-03 10:00', 'TK-2', 'correct', 'auto', ['session' => 'S2']);
        $this->assertHasFlag($s->project('2026-10-04 10:00'), 'T', 'overconfident', false);
    }

    public function test_underconfident_needs_two_qualifying_successes_after_the_latest_uncertain_report(): void
    {
        $s = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->exercises('TK-1', 'T')->exercises('TK-2', 'T')
            ->selfReport('LOST', '2026-10-01 09:00', 'confused', ['topic:T'])
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1']);
        $this->assertHasFlag($s->project('2026-10-02 10:00'), 'T', 'underconfident', false);

        $s->attempt('A2', '2026-10-03 10:00', 'TK-2', 'correct', 'auto', ['session' => 'S2']);
        $this->assertHasFlag($s->project('2026-10-04 10:00'), 'T', 'underconfident');
    }

    public function test_weak_part_looks_through_nested_sub_topics(): void
    {
        $p = Scenario::make()->topic('TOP')->topic('MID')->topic('LEAF')->partOf('MID', 'TOP')->partOf('LEAF', 'MID')
            ->task('TK-TOP')->exercises('TK-TOP', 'TOP')->task('TK-LEAF')->exercises('TK-LEAF', 'LEAF')
            ->attempt('A1', '2026-10-01 10:00', 'TK-TOP', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-01 11:00', 'TK-LEAF', 'incorrect', 'auto', ['session' => 'S1'])
            ->project('2026-10-02 10:00');

        $this->assertTopic($p, 'LEAF', 'developing');
        $this->assertHasFlag($p, 'TOP', 'weak_part');
    }

    public function test_exam_relevant_needs_an_effective_emphasis(): void
    {
        $s = Scenario::make()->topic('T')->activity('A-L7', 'lecture', '2026-10-01 10:00')
            ->claim('EMPH', 'relates', ['activity:A-L7', 'topic:T'], ['relation' => 'emphasizes', 'qualifiers' => ['emphasis' => 'exam_relevant'], 'status' => 'active'],
                at: '2026-10-01 12:00', state: 'pending', derivedFrom: ['source:SRC#00:34:10']);
        $this->assertHasFlag($s->project('2026-10-02 10:00'), 'T', 'exam_relevant', false);

        $s->learnerReview('YES', 'claim:EMPH', 'accept', '2026-10-01 13:00');
        $this->assertHasFlag($s->project('2026-10-02 10:00'), 'T', 'exam_relevant');
    }

    public function test_a_merged_topic_disappears_and_its_evidence_counts_for_the_survivor(): void
    {
        $p = Scenario::make()->topic('A')->topic('B')->task('TK-1')->exercises('TK-1', 'A')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->sameAs('SAME', 'topic', 'A', 'B', '2026-10-02 10:00')
            ->project('2026-10-03 10:00');

        $this->assertArrayNotHasKey('A', $p['topics']);
        $this->assertTopic($p, 'B', 'working');
    }

    public function test_a_slip_is_never_a_regression_point(): void
    {
        $s = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->exercises('TK-1', 'T')->exercises('TK-2', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-05 10:00', 'TK-2', 'incorrect', 'auto', ['session' => 'S2']);
        // An unjudged cause counts as not a slip.
        $this->assertTopic($s->project('2026-10-06 10:00'), 'T', 'developing', ['regressed']);

        $s->judges('J2', 'A2', ['cause' => 'slip'], '2026-10-05 11:00');
        $this->assertTopic($s->project('2026-10-06 10:00'), 'T', 'working', []);
    }
}
