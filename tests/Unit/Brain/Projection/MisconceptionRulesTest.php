<?php

namespace Tests\Unit\Brain\Projection;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Brain\Projection\Support\ProjectionAssertions;
use Tests\Unit\Brain\Projection\Support\Scenario;

/** ADR 0002 §7 misconception lifecycle, and how an active misconception holds a topic back. */
class MisconceptionRulesTest extends TestCase
{
    use ProjectionAssertions;

    private function exhibit(Scenario $s, string $attempt, string $task, string $at, string $session, bool $present = true, string $outcome = 'incorrect'): Scenario
    {
        return $s->attempt($attempt, $at, $task, $outcome, 'auto', ['session' => $session])
            ->judges("J-{$attempt}", $attempt, ['misconceptions' => [['misconception' => 'misconception:M', 'present' => $present]]], $at);
    }

    private function base(): Scenario
    {
        $s = Scenario::make()->topic('T');
        foreach (['TK-1', 'TK-2', 'TK-3', 'TK-4', 'TK-5'] as $task) {
            $s->task($task)->exercises($task, 'T');
        }

        return $s;
    }

    public function test_detected_then_recurring_then_addressed_holds_the_topic_at_developing(): void
    {
        $s = $this->base()->misconception('DEF', 'M', ['T'], '2026-10-01 09:00');
        $this->exhibit($s, 'A1', 'TK-1', '2026-10-01 10:00', 'S1');
        $this->assertMisconception($s->project('2026-10-01 11:00'), 'M', 'detected');

        $this->exhibit($s, 'A2', 'TK-2', '2026-10-01 10:30', 'S1');
        $this->assertMisconception($s->project('2026-10-01 11:00'), 'M', 'recurring');

        $s->exposure('FIX', '2026-10-01 12:00', ['topic:T'], ['session' => 'S1'])->addresses('ADDR', 'FIX', 'M', '2026-10-01 12:00');
        $s->attempt('A3', '2026-10-02 10:00', 'TK-3', 'correct', 'auto', ['session' => 'S2']);
        $p = $s->project('2026-10-02 11:00');
        $this->assertMisconception($p, 'M', 'addressed');
        $this->assertTopic($p, 'T', 'developing', null);
    }

    public function test_counters_on_two_tasks_one_in_a_later_session_resolve_it_and_retention_confirms_it(): void
    {
        $s = $this->base()->misconception('DEF', 'M', ['T'], '2026-10-01 09:00');
        $this->exhibit($s, 'A1', 'TK-1', '2026-10-01 10:00', 'S1');
        $this->exhibit($s, 'C1', 'TK-2', '2026-10-01 10:30', 'S1', present: false, outcome: 'correct');
        $this->assertMisconception($s->project('2026-10-01 11:00'), 'M', 'detected', 0);

        $this->exhibit($s, 'C2', 'TK-3', '2026-10-02 10:00', 'S2', present: false, outcome: 'correct');
        $p = $s->project('2026-10-02 11:00');
        $this->assertMisconception($p, 'M', 'apparently_resolved');
        $this->assertTopic($p, 'T', 'working');

        $this->exhibit($s, 'C3', 'TK-4', '2026-10-30 10:00', 'S3', present: false, outcome: 'correct');
        $this->assertMisconception($s->project('2026-10-30 11:00'), 'M', 'resolved_retained');

        // An exhibit after resolution: resurfaced, counted once.
        $this->exhibit($s, 'A5', 'TK-5', '2026-11-05 10:00', 'S4');
        $p = $s->project('2026-11-05 11:00');
        $this->assertMisconception($p, 'M', 'resurfaced', 1);
        $this->assertTopic($p, 'T', 'developing');
    }

    public function test_counters_in_the_same_session_as_the_last_exhibit_are_not_enough(): void
    {
        $s = $this->base()->misconception('DEF', 'M', ['T'], '2026-10-01 09:00');
        $this->exhibit($s, 'A1', 'TK-1', '2026-10-01 10:00', 'S1');
        $this->exhibit($s, 'C1', 'TK-2', '2026-10-01 10:30', 'S1', present: false, outcome: 'correct');
        $this->exhibit($s, 'C2', 'TK-3', '2026-10-01 10:40', 'S1', present: false, outcome: 'correct');

        $this->assertMisconception($s->project('2026-10-01 11:00'), 'M', 'detected');
    }

    public function test_a_pending_definition_has_no_effect_and_a_rejected_one_is_withdrawn(): void
    {
        $s = $this->base()->misconception('DEF', 'M', ['T'], '2026-10-01 09:00', state: 'pending');
        $this->exhibit($s, 'A1', 'TK-1', '2026-10-01 10:00', 'S1');
        $s->attempt('OK', '2026-10-02 10:00', 'TK-2', 'correct', 'auto', ['session' => 'S2']);

        $p = $s->project('2026-10-02 11:00');
        $this->assertMisconception($p, 'M', null);
        $this->assertTopic($p, 'T', 'working');

        // A misconception evaluates the learner's work, so the learner may
        // only dispute it (ADR 0002 §6); their rejection is ignored.
        $s->learnerReview('NO', 'claim:DEF', 'reject', '2026-10-02 12:00');
        $this->assertMisconception($s->project('2026-10-02 13:00'), 'M', null);

        $s->claim('REJECT', 'reviews', ['claim:DEF'], ['decision' => 'reject', 'reason' => 'wrong_diagnosis'], method: 'interpreter', at: '2026-10-02 14:00');
        $this->assertMisconception($s->project('2026-10-02 15:00'), 'M', 'withdrawn');
    }

    public function test_a_retired_misconception_no_longer_holds_the_topic_back(): void
    {
        $s = $this->base()->misconception('DEF', 'M', ['T'], '2026-10-01 09:00');
        $this->exhibit($s, 'A1', 'TK-1', '2026-10-01 10:00', 'S1');
        $s->attempt('OK', '2026-10-02 10:00', 'TK-2', 'correct', 'auto', ['session' => 'S2']);
        $this->assertTopic($s->project('2026-10-02 11:00'), 'T', 'developing');

        $s->claim('RETIRE', 'defines', ['misconception:M'], ['entity_type' => 'misconception', 'status' => 'retired', 'topics' => ['topic:T']], method: 'person', at: '2026-10-03 10:00');
        $p = $s->project('2026-10-03 11:00');
        $this->assertMisconception($p, 'M', null);
        $this->assertTopic($p, 'T', 'working');
    }
}
