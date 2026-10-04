<?php

namespace Tests\Unit\Brain\Projection;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Brain\Projection\Support\ProjectionAssertions;
use Tests\Unit\Brain\Projection\Support\Scenario;

/**
 * ADR 0002 §6 disputes and withdrawals, with the golden replay's edge
 * cases D1–D6 as developer tests. A dispute suspends the evaluation it
 * covers; it never creates or restores favourable evidence.
 */
class DisputeRulesTest extends TestCase
{
    use ProjectionAssertions;

    /** The chat AI recorded "correct"; the interpreter judged "incorrect". */
    private function chatVersusInterpreter(): Scenario
    {
        return Scenario::make()->topic('T')->task('TK-1')->exercises('TK-1', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'ai', ['session' => 'S1'])
            ->judges('INTERP', 'A1', ['overall' => 'incorrect'], '2026-10-01 10:30');
    }

    public function test_d1_the_learner_cannot_reject_a_verdict_on_their_own_work(): void
    {
        $p = $this->chatVersusInterpreter()->learnerReview('REJECT', 'claim:INTERP', 'reject', '2026-10-01 11:00')->project('2026-10-02 10:00');

        $this->assertSame('incorrect', $p['attempts']['A1']['overall']);
        $this->assertTopic($p, 'T', 'developing');
    }

    public function test_d2_a_dispute_suspends_and_never_restores_the_chat_ai_verdict(): void
    {
        $p = $this->chatVersusInterpreter()->learnerReview('DISPUTE', 'claim:INTERP', 'dispute', '2026-10-01 11:00')->project('2026-10-02 10:00');

        $this->assertSame('disputed', $p['attempts']['A1']['overall']);
        $this->assertSame(['T' => 'disputed'], $p['attempts']['A1']['topics']);
        $this->assertTopic($p, 'T', 'developing');
    }

    public function test_d3_withdrawing_the_dispute_restores_the_verdict(): void
    {
        $p = $this->chatVersusInterpreter()
            ->learnerReview('DISPUTE', 'claim:INTERP', 'dispute', '2026-10-01 11:00')
            ->learnerReview('WITHDRAW', 'claim:DISPUTE', 'withdraw', '2026-10-01 12:00')
            ->project('2026-10-02 10:00');

        $this->assertSame('incorrect', $p['attempts']['A1']['overall']);
    }

    public function test_d4_only_an_adjudication_citing_the_dispute_with_enough_authority_settles_it(): void
    {
        $s = $this->chatVersusInterpreter()
            ->learnerReview('DISPUTE', 'claim:INTERP', 'dispute', '2026-10-01 11:00')
            ->judges('NOT-CITING', 'A1', ['overall' => 'correct'], '2026-10-01 12:00')
            ->judges('CHAT-CITING', 'A1', ['overall' => 'correct'], '2026-10-01 12:10', ['method' => 'chat_ai', 'derived_from' => ['claim:DISPUTE']]);
        $this->assertSame('disputed', $s->project('2026-10-02 10:00')['attempts']['A1']['overall']);

        $s->judges('ADJUDICATION', 'A1', ['overall' => 'correct'], '2026-10-01 12:20', ['derived_from' => ['claim:DISPUTE']]);
        $p = $s->project('2026-10-02 10:00');
        $this->assertSame('correct', $p['attempts']['A1']['overall']);
        $this->assertTopic($p, 'T', 'working');
    }

    public function test_d5_an_auto_outcome_is_settled_only_by_rerunning_the_checker(): void
    {
        $s = Scenario::make()->topic('T')->task('TK-1')->exercises('TK-1', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'incorrect', 'auto', ['session' => 'S1'])
            ->learnerReview('DISPUTE', 'event:A1', 'dispute', '2026-10-01 11:00')
            ->judges('INTERP', 'A1', ['overall' => 'correct'], '2026-10-01 12:00', ['derived_from' => ['claim:DISPUTE']]);
        $this->assertSame('disputed', $s->project('2026-10-02 10:00')['attempts']['A1']['overall']);

        $s->judges('RERUN', 'A1', ['overall' => 'correct'], '2026-10-01 12:30', ['method' => 'auto', 'derived_from' => ['claim:DISPUTE']]);
        $this->assertSame('correct', $s->project('2026-10-02 10:00')['attempts']['A1']['overall']);
    }

    public function test_d6_disputing_a_misconception_lifts_its_block_until_a_new_exhibit(): void
    {
        $s = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->task('TK-3')->exercises('TK-1', 'T')->exercises('TK-2', 'T')->exercises('TK-3', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'incorrect', 'auto', ['session' => 'S1'])
            ->misconception('DEF', 'M', ['T'], '2026-10-01 10:30')
            ->judges('J1', 'A1', ['misconceptions' => [['misconception' => 'misconception:M', 'present' => true]]], '2026-10-01 10:30')
            ->attempt('A2', '2026-10-02 10:00', 'TK-2', 'correct', 'auto', ['session' => 'S2']);
        $this->assertTopic($s->project('2026-10-03 10:00'), 'T', 'developing');

        $s->learnerReview('DISPUTE', 'claim:DEF', 'dispute', '2026-10-03 11:00');
        $p = $s->project('2026-10-04 10:00');
        $this->assertMisconception($p, 'M', 'disputed');
        $this->assertTopic($p, 'T', 'working', ['rests_on_dispute']);

        // A new exhibit, on an attempt made after the dispute, makes it active again.
        $s->attempt('A3', '2026-10-05 10:00', 'TK-3', 'incorrect', 'auto', ['session' => 'S3'])
            ->judges('J3', 'A3', ['misconceptions' => [['misconception' => 'misconception:M', 'present' => true]]], '2026-10-05 10:30');
        $p = $s->project('2026-10-06 10:00');
        $this->assertMisconception($p, 'M', 'recurring');
        $this->assertTopic($p, 'T', 'developing');
    }

    public function test_an_exhibit_on_an_attempt_made_before_the_dispute_does_not_lift_it(): void
    {
        $p = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->exercises('TK-1', 'T')->exercises('TK-2', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'incorrect', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-01 11:00', 'TK-2', 'incorrect', 'auto', ['session' => 'S1'])
            ->misconception('DEF', 'M', ['T'], '2026-10-01 12:00')
            ->judges('J1', 'A1', ['misconceptions' => [['misconception' => 'misconception:M', 'present' => true]]], '2026-10-01 12:00')
            ->learnerReview('DISPUTE', 'claim:DEF', 'dispute', '2026-10-02 10:00')
            ->judges('J2', 'A2', ['misconceptions' => [['misconception' => 'misconception:M', 'present' => true]]], '2026-10-02 11:00')
            ->project('2026-10-03 10:00');

        $this->assertMisconception($p, 'M', 'disputed');
    }

    public function test_a_dispute_covers_only_the_facets_it_names(): void
    {
        $p = Scenario::make()->topic('T')->task('TK-1')->exercises('TK-1', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'unjudged', 'none', ['session' => 'S1'])
            ->judges('INTERP', 'A1', ['overall' => 'correct', 'cause' => 'slip'], '2026-10-01 10:30')
            ->learnerReview('DISPUTE', 'claim:INTERP', 'dispute', '2026-10-01 11:00', ['facets' => ['cause']])
            ->project('2026-10-02 10:00');

        $this->assertSame('correct', $p['attempts']['A1']['overall']);
    }

    public function test_the_learner_may_reject_claims_about_meaning_and_withdraw_that_rejection(): void
    {
        $s = Scenario::make()->topic('T')->topic('U')->task('TK-1')->exercises('TK-1', 'T')
            ->claim('MERGE', 'same_as', ['topic:U', 'topic:T'], ['survivor' => 'topic:T'], method: 'rule', at: '2026-10-01 09:00')
            ->learnerReview('REJECT', 'claim:MERGE', 'reject', '2026-10-01 10:00');
        $this->assertArrayHasKey('U', $s->project('2026-10-02 10:00')['topics']);

        $s->learnerReview('UNDO', 'claim:REJECT', 'withdraw', '2026-10-01 11:00');
        $this->assertArrayNotHasKey('U', $s->project('2026-10-02 10:00')['topics']);
    }

    public function test_a_learner_cannot_withdraw_someone_elses_review(): void
    {
        $p = Scenario::make()->topic('T')->task('TK-1')->exercises('TK-1', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'unjudged', 'none', ['session' => 'S1'])
            ->judges('INTERP', 'A1', ['overall' => 'incorrect'], '2026-10-01 10:30')
            ->claim('REJECT', 'reviews', ['claim:INTERP'], ['decision' => 'reject', 'reason' => 'wrong_outcome'], method: 'person', at: '2026-10-01 11:00')
            ->learnerReview('WITHDRAW', 'claim:REJECT', 'withdraw', '2026-10-01 12:00')
            ->project('2026-10-02 10:00');

        $this->assertNull($p['attempts']['A1']['overall']);
    }
}
