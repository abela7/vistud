<?php

namespace Tests\Unit\Brain\Projection;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Brain\Projection\Support\ProjectionAssertions;
use Tests\Unit\Brain\Projection\Support\Scenario;

/** ADR 0002 §7 question lifecycle: opening events, answers, resolution, reopening. */
class QuestionRulesTest extends TestCase
{
    use ProjectionAssertions;

    private function asked(): Scenario
    {
        return Scenario::make()->topic('T')->task('TK-1')->exercises('TK-1', 'T')
            ->ask('ASK', '2026-10-01 19:00', ['topic:T'], 'S1')
            ->question('DEF', 'Q', '2026-10-01 19:00')
            ->refersTo('REF', 'ASK', 'Q', '2026-10-01 19:00')
            ->stemsFrom('STEM', 'Q', 'topic:T', '2026-10-01 19:00');
    }

    public function test_the_states_in_order_open_being_answered_answered(): void
    {
        $s = $this->asked();
        $this->assertQuestion($s->project('2026-10-01 19:01'), 'Q', 'open', [], 0);

        $s->exposure('ANSWER', '2026-10-01 19:02', ['topic:T'], ['by' => 'chat_ai', 'responds_to' => 'ASK', 'session' => 'S1']);
        $this->assertQuestion($s->project('2026-10-01 19:03'), 'Q', 'being_answered');

        $s->judges('J', 'ANSWER', ['answer' => [['question' => 'question:Q', 'adequacy' => 'full']]], '2026-10-01 19:30');
        $this->assertQuestion($s->project('2026-10-01 19:31'), 'Q', 'answered');
    }

    public function test_a_partial_answer_then_a_confident_learner_is_partially_answered_with_a_flag(): void
    {
        $p = $this->asked()
            ->exposure('ANSWER', '2026-10-01 19:02', ['topic:T'], ['by' => 'chat_ai', 'responds_to' => 'ASK', 'session' => 'S1'])
            ->selfReport('GOT-IT', '2026-10-01 19:05', 'clicked', ['question:Q'], 'S1')
            ->judges('J', 'ANSWER', ['answer' => [['question' => 'question:Q', 'adequacy' => 'partial']]], '2026-10-01 19:30')
            ->project('2026-10-01 19:31');

        $this->assertQuestion($p, 'Q', 'partially_answered', ['learner_thought_resolved']);
    }

    public function test_uncertainty_after_an_answer_is_partially_answered(): void
    {
        $p = $this->asked()
            ->exposure('ANSWER', '2026-10-01 19:02', ['topic:T'], ['by' => 'chat_ai', 'responds_to' => 'ASK', 'session' => 'S1'])
            ->selfReport('HUH', '2026-10-01 19:05', 'unsure', ['question:Q'], 'S1')
            ->project('2026-10-01 19:31');

        $this->assertQuestion($p, 'Q', 'partially_answered');
    }

    public function test_demonstration_needs_an_unaided_correct_attempt_and_topic_overlap_never_counts(): void
    {
        $s = $this->asked()
            ->attempt('OVERLAP', '2026-10-02 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S2'])
            ->attempt('HINTED', '2026-10-03 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S3', 'support' => 'hinted'])
            ->judges('JH', 'HINTED', ['demonstrates' => ['question:Q']], '2026-10-03 10:30');
        $this->assertQuestion($s->project('2026-10-04 10:00'), 'Q', 'open');

        $s->attempt('SHOWS', '2026-10-05 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S4'])
            ->judges('JS', 'SHOWS', ['demonstrates' => ['question:Q']], '2026-10-05 10:30');
        $this->assertQuestion($s->project('2026-10-06 10:00'), 'Q', 'resolved_demonstrated');
    }

    public function test_asking_again_after_resolution_resurfaces_the_question(): void
    {
        $p = $this->asked()
            ->exposure('ANSWER', '2026-10-01 19:02', ['topic:T'], ['by' => 'chat_ai', 'responds_to' => 'ASK', 'session' => 'S1'])
            ->selfReport('GOT-IT', '2026-10-01 19:05', 'clicked', ['question:Q'], 'S1')
            ->ask('AGAIN', '2026-10-10 19:00', [], 'S2')
            ->refersTo('REF2', 'AGAIN', 'Q', '2026-10-10 19:00')
            ->project('2026-10-10 19:01');

        $this->assertQuestion($p, 'Q', 'open', [], 1);
    }

    public function test_an_uncertain_self_report_reopens_a_resolved_question(): void
    {
        // V5.
        $s = $this->asked()
            ->exposure('ANSWER', '2026-10-01 19:02', ['topic:T'], ['by' => 'chat_ai', 'responds_to' => 'ASK', 'session' => 'S1'])
            ->selfReport('GOT-IT', '2026-10-01 19:05', 'clicked', ['question:Q'], 'S1');
        $this->assertQuestion($s->project('2026-10-01 19:06'), 'Q', 'resolved_learner_confirmed');

        $s->selfReport('UNSURE', '2026-10-09 21:00', 'unsure', ['question:Q'], 'S9');
        $this->assertQuestion($s->project('2026-10-09 21:01'), 'Q', 'open', ['reopened'], 1);

        $s->exposure('AGAIN', '2026-10-09 21:02', ['topic:T'], ['by' => 'chat_ai', 'responds_to' => 'UNSURE', 'session' => 'S9']);
        $this->assertQuestion($s->project('2026-10-09 21:03'), 'Q', 'being_answered', ['reopened'], 1);

        // A self-report never changes a topic label.
        $this->assertTopic($s->project('2026-10-09 21:03'), 'T', 'introduced');
    }

    public function test_an_uncertain_self_report_on_an_unresolved_question_does_not_reopen_it(): void
    {
        $p = $this->asked()
            ->selfReport('UNSURE', '2026-10-02 21:00', 'confused', ['question:Q'], 'S2')
            ->project('2026-10-02 21:01');

        $this->assertQuestion($p, 'Q', 'open', [], 0);
    }

    public function test_questions_go_dormant_after_21_days_without_evidence_unless_resolved(): void
    {
        $s = $this->asked();
        $this->assertQuestion($s->project('2026-10-22 18:59'), 'Q', 'open', []);
        $this->assertQuestion($s->project('2026-10-22 19:00'), 'Q', 'open', ['dormant']);
    }

    public function test_rejecting_a_match_moves_the_ask_and_reports_the_rejected_pair(): void
    {
        // V1.
        $p = $this->asked()
            ->exposure('ANSWER', '2026-10-01 19:02', ['topic:T'], ['by' => 'chat_ai', 'responds_to' => 'ASK', 'session' => 'S1'])
            ->learnerReview('NO', 'claim:REF', 'reject', '2026-10-01 20:00')
            ->question('DEF2', 'Q2', '2026-10-01 20:00')
            ->refersTo('REF2', 'ASK', 'Q2', '2026-10-01 20:00')
            ->project('2026-10-01 20:01');

        $this->assertQuestion($p, 'Q', 'open', [], 0);
        $this->assertQuestion($p, 'Q2', 'being_answered');
        $this->assertSame([['target' => 'event:ASK', 'entity' => 'question:Q']], $p['rejected_pairs']);
    }

    public function test_merged_questions_share_their_evidence_and_the_survivor_is_flagged(): void
    {
        $p = $this->asked()
            ->ask('ASK2', '2026-10-02 19:00', [], 'S2')
            ->question('DEF2', 'Q2', '2026-10-02 19:00')
            ->refersTo('REF2', 'ASK2', 'Q2', '2026-10-02 19:00')
            ->sameAs('SAME', 'question', 'Q2', 'Q', '2026-10-02 19:10')
            ->project('2026-10-02 19:11');

        $this->assertArrayNotHasKey('Q2', $p['questions']);
        $this->assertQuestion($p, 'Q', 'open', ['merged'], 0);
    }

    public function test_a_defined_question_that_nothing_has_asked_is_open_and_a_retired_one_is_gone(): void
    {
        $p = Scenario::make()->question('DEF', 'Q', '2026-10-01 10:00')
            ->claim('RETIRE', 'defines', ['question:OLD'], ['entity_type' => 'question', 'status' => 'retired'], method: 'rule', at: '2026-10-01 10:00')
            ->project('2026-10-02 10:00');

        $this->assertQuestion($p, 'Q', 'open', [], 0);
        $this->assertArrayNotHasKey('OLD', $p['questions']);
    }

    public function test_undoing_a_merge_restores_both_separate_histories(): void
    {
        // PM ruling WP4-Q4: merged belongs to the survivor, and the merge can be undone.
        $s = $this->asked()
            ->exposure('ANSWER', '2026-10-01 19:02', ['topic:T'], ['by' => 'chat_ai', 'responds_to' => 'ASK', 'session' => 'S1'])
            ->judges('J', 'ANSWER', ['answer' => [['question' => 'question:Q', 'adequacy' => 'full']]], '2026-10-01 19:30')
            ->ask('ASK2', '2026-10-05 19:00', [], 'S2')
            ->question('DEF2', 'Q2', '2026-10-05 19:00')
            ->refersTo('REF2', 'ASK2', 'Q2', '2026-10-05 19:00');
        $before = $s->project('2026-10-05 19:05');

        $s->sameAs('SAME', 'question', 'Q2', 'Q', '2026-10-05 19:10');
        $merged = $s->project('2026-10-05 19:15');
        $this->assertArrayNotHasKey('Q2', $merged['questions']);
        // Q was answered, not resolved, when ASK2 came in: not a resurfacing.
        $this->assertQuestion($merged, 'Q', 'open', ['merged'], 0);

        $s->learnerReview('UNDO', 'claim:SAME', 'reject', '2026-10-05 19:20');
        $undone = $s->project('2026-10-05 19:05');
        $this->assertSame($before['questions'], $undone['questions']);
        $this->assertSame([['target' => 'question:Q2', 'entity' => 'question:Q']], $undone['rejected_pairs']);
    }

    public function test_a_defined_but_never_asked_question_gets_no_invented_activity(): void
    {
        // PM ruling WP4-Q6: open, with no ask, resurfacing or dormancy made up.
        $s = Scenario::make()->topic('T')->question('DEF', 'Q', '2026-10-01 10:00');
        $this->assertQuestion($s->project('2027-06-01 10:00'), 'Q', 'open', [], 0);

        // Uncertainty about it doesn't reopen or resurface anything.
        $s->selfReport('UNSURE', '2026-10-02 10:00', 'unsure', ['question:Q']);
        $this->assertQuestion($s->project('2026-10-03 10:00'), 'Q', 'open', [], 0);
        $this->assertTopic($s->project('2026-10-03 10:00'), 'T', 'not_started');
    }
}
