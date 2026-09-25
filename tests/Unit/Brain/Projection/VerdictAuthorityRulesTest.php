<?php

namespace Tests\Unit\Brain\Projection;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Brain\Projection\Support\ProjectionAssertions;
use Tests\Unit\Brain\Projection\Support\Scenario;

/** ADR 0002 §6: authority, supersession, rejection and the fallback to the overall outcome. */
class VerdictAuthorityRulesTest extends TestCase
{
    use ProjectionAssertions;

    private function base(): Scenario
    {
        return Scenario::make()->topic('T')->topic('SUB')->partOf('SUB', 'T')->task('TK-1')->exercises('TK-1', 'T', 'SUB');
    }

    public function test_the_highest_authority_wins_and_the_loser_is_shown_as_overridden(): void
    {
        $p = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'incorrect', 'auto', ['session' => 'S1'])
            ->judges('INTERP', 'A1', ['overall' => 'correct'], '2026-10-01 10:30')
            ->judges('CHAT', 'A1', ['overall' => 'correct'], '2026-10-01 10:40', ['method' => 'chat_ai'])
            ->project('2026-10-02 10:00');

        $this->assertSame('incorrect', $p['attempts']['A1']['overall']);
        $this->assertSame(['CHAT', 'INTERP'], $p['attempts']['A1']['overridden']);
        $this->assertTrue($p['attempts']['A1']['checker_suspect'], 'The interpreter disagrees with auto');
    }

    public function test_among_equal_authority_an_explicit_supersedes_wins_over_a_later_position(): void
    {
        $p = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'unjudged', 'none', ['session' => 'S1'])
            ->judges('FIRST', 'A1', ['overall' => 'incorrect'], '2026-10-01 10:30')
            ->judges('SECOND', 'A1', ['overall' => 'correct'], '2026-10-01 10:40', ['supersedes' => ['claim:FIRST']])
            ->judges('THIRD', 'A1', ['overall' => 'incorrect'], '2026-10-01 10:50', ['supersedes' => ['claim:FIRST']])
            ->project('2026-10-02 10:00');

        // SECOND and THIRD both supersede FIRST; between them, the latest position wins.
        $this->assertSame('incorrect', $p['attempts']['A1']['overall']);

        $p = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'unjudged', 'none', ['session' => 'S1'])
            ->judges('FIRST', 'A1', ['overall' => 'incorrect'], '2026-10-01 10:30')
            ->judges('SECOND', 'A1', ['overall' => 'correct'], '2026-10-01 10:40')
            ->judges('CORRECTION', 'A1', ['overall' => 'partial'], '2026-10-01 10:35')
            ->judges('FIX', 'A1', ['overall' => 'incorrect'], '2026-10-01 10:30', ['supersedes' => ['claim:SECOND', 'claim:CORRECTION']])
            ->project('2026-10-02 10:00');
        $this->assertSame('incorrect', $p['attempts']['A1']['overall']);
    }

    public function test_a_lower_authority_verdict_can_never_supersede_a_higher_one(): void
    {
        $p = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'unjudged', 'none', ['session' => 'S1'])
            ->judges('INTERP', 'A1', ['overall' => 'incorrect'], '2026-10-01 10:30')
            ->judges('CHAT', 'A1', ['overall' => 'correct'], '2026-10-01 10:40', ['method' => 'chat_ai', 'supersedes' => ['claim:INTERP']])
            ->project('2026-10-02 10:00');

        $this->assertSame('incorrect', $p['attempts']['A1']['overall']);
    }

    public function test_pending_verdicts_have_no_effect_until_accepted(): void
    {
        $s = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'unjudged', 'none', ['session' => 'S1'])
            ->judges('J1', 'A1', ['overall' => 'correct'], '2026-10-01 10:30', ['method' => 'chat_ai', 'state' => 'pending']);
        $this->assertNull($s->project('2026-10-02 10:00')['attempts']['A1']['overall']);

        // Chat verdicts are never accepted automatically (policy@1), but an authorised reviewer can.
        $s->claim('ACCEPT', 'reviews', ['claim:J1'], ['decision' => 'accept', 'reason' => 'confirmed'], method: 'interpreter', at: '2026-10-01 11:00');
        $this->assertSame('correct', $s->project('2026-10-02 10:00')['attempts']['A1']['overall']);
    }

    public function test_a_rejection_by_an_authorised_reviewer_falls_back_to_what_remains(): void
    {
        $s = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'ai', ['session' => 'S1'])
            ->judges('INTERP', 'A1', ['overall' => 'incorrect'], '2026-10-01 10:30')
            ->claim('REJECT', 'reviews', ['claim:INTERP'], ['decision' => 'reject', 'reason' => 'wrong_outcome'], method: 'interpreter', at: '2026-10-01 11:00');

        $this->assertSame('correct', $s->project('2026-10-02 10:00')['attempts']['A1']['overall']);
    }

    public function test_a_reviewer_of_lower_authority_cannot_reject(): void
    {
        $p = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'unjudged', 'none', ['session' => 'S1'])
            ->judges('INTERP', 'A1', ['overall' => 'incorrect'], '2026-10-01 10:30')
            ->claim('REJECT', 'reviews', ['claim:INTERP'], ['decision' => 'reject', 'reason' => 'wrong_outcome'], method: 'chat_ai', at: '2026-10-01 11:00')
            ->project('2026-10-02 10:00');

        $this->assertSame('incorrect', $p['attempts']['A1']['overall']);
    }

    public function test_correct_applies_to_every_exercised_topic_and_failure_only_to_the_most_specific(): void
    {
        $p = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'incorrect', 'auto', ['session' => 'S1'])
            ->project('2026-10-02 10:00');
        $this->assertSame(['T' => null, 'SUB' => 'incorrect'], $p['attempts']['A1']['topics']);

        $p = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->project('2026-10-02 10:00');
        $this->assertSame(['T' => 'correct', 'SUB' => 'correct'], $p['attempts']['A1']['topics']);
    }

    public function test_a_verdict_naming_a_topic_makes_the_attempt_an_attempt_on_it(): void
    {
        $p = Scenario::make()->topic('T')->topic('OTHER')->task('TK-1')->exercises('TK-1', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->judges('J1', 'A1', ['topics' => [['topic' => 'topic:OTHER', 'outcome' => 'incorrect']]], '2026-10-01 10:30')
            ->project('2026-10-02 10:00');

        $this->assertSame(['T' => 'correct', 'OTHER' => 'incorrect'], $p['attempts']['A1']['topics']);
        $this->assertTopic($p, 'OTHER', 'developing');
    }

    public function test_aided_attempts_are_neither_successes_nor_failures(): void
    {
        $p = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1', 'support' => 'hinted'])
            ->project('2026-10-02 10:00');

        $this->assertTopic($p, 'T', 'developing');
    }

    public function test_a_verdict_on_support_overrides_the_recorded_support(): void
    {
        $p = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->judges('J1', 'A1', ['support' => 'followed_example'], '2026-10-01 10:30')
            ->project('2026-10-02 10:00');

        $this->assertTopic($p, 'T', 'developing');
    }

    public function test_includes_ai_judged_marks_labels_that_rest_on_chat_or_interpreter_outcomes(): void
    {
        $s = $this->base()->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'ai', ['session' => 'S1']);
        $this->assertTopic($s->project('2026-10-02 10:00'), 'T', 'working', ['includes_ai_judged']);

        $s->attempt('A2', '2026-10-03 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S2']);
        $this->assertTopic($s->project('2026-10-04 10:00'), 'T', 'working', []);
    }
}
