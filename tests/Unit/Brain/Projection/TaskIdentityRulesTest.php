<?php

namespace Tests\Unit\Brain\Projection;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Brain\Projection\Support\ProjectionAssertions;
use Tests\Unit\Brain\Projection\Support\Scenario;

/**
 * ADR 0002 §4 (task identity) and §7 (repeats, diversity). Developer tests
 * for WP4, one rule each.
 */
class TaskIdentityRulesTest extends TestCase
{
    use ProjectionAssertions;

    private function base(): Scenario
    {
        return Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->exercises('TK-1', 'T')->exercises('TK-2', 'T');
    }

    public function test_diversity_counts_task_ids_so_a_delayed_repeat_adds_none(): void
    {
        // X1: the same task on day 1 and day 3, plus an explanation.
        $s = $this->base()->task('TK-EX')->exercises('TK-EX', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-03 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S2'])
            ->attempt('A3', '2026-10-03 11:00', 'TK-EX', 'correct', 'auto', ['session' => 'S2', 'form' => 'explain'])
            ->judges('J3', 'A3', ['own_words' => true], '2026-10-03 11:30');

        $p = $s->project('2026-10-04 10:00');
        $this->assertSame('delayed', $p['attempts']['A2']['repeat']);
        $this->assertTopic($p, 'T', 'working');

        // A second apply task on day 5 makes it secure.
        $s->attempt('A4', '2026-10-05 10:00', 'TK-2', 'correct', 'auto', ['session' => 'S3']);
        $this->assertTopic($s->project('2026-10-06 10:00'), 'T', 'secure');
    }

    public function test_revising_a_task_keeps_its_identity_and_adds_no_diversity(): void
    {
        // ADR 0002 §4: a meaningful change is a revision of the same task.
        $s = $this->base()->task('TK-EX')->exercises('TK-EX', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1', 'revision' => 1])
            ->reviseTask('TK-1', 2, '2026-10-02 09:00')
            ->attempt('A2', '2026-10-03 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S2', 'revision' => 2])
            ->attempt('A3', '2026-10-03 11:00', 'TK-EX', 'correct', 'auto', ['session' => 'S2', 'form' => 'explain'])
            ->judges('J3', 'A3', ['own_words' => true], '2026-10-03 11:30');

        $p = $s->project('2026-10-04 10:00');
        $this->assertSame(['TK-1', 'TK-1'], [$p['attempts']['A1']['task'], $p['attempts']['A2']['task']]);
        $this->assertSame(1, $p['topics']['T']['facts']['apply_tasks']);
        $this->assertTopic($p, 'T', 'working');
    }

    public function test_an_immediate_retry_after_teaching_counts_for_nothing_positive(): void
    {
        // X2: fail, taught, the same task correct 20 minutes later.
        $s = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'incorrect', 'auto', ['session' => 'S1'])
            ->exposure('X1', '2026-10-01 10:05', ['topic:T'], ['session' => 'S1'])
            ->attempt('A2', '2026-10-01 10:25', 'TK-1', 'correct', 'auto', ['session' => 'S2']);

        $p = $s->project('2026-10-02 10:00');
        $this->assertSame('immediate', $p['attempts']['A2']['repeat']);
        $this->assertTopic($p, 'T', 'developing');
    }

    public function test_a_repeat_is_delayed_only_in_another_session_a_day_later_without_teaching_in_the_day_before(): void
    {
        $p = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('SAME-SESSION', '2026-10-03 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('UNDER-A-DAY', '2026-10-04 09:59', 'TK-1', 'correct', 'auto', ['session' => 'S2'])
            ->exposure('TEACH', '2026-10-06 12:00', ['topic:T'], ['session' => 'S3'])
            ->attempt('AFTER-TEACHING', '2026-10-07 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S4'])
            ->attempt('DELAYED', '2026-10-09 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S5'])
            ->project('2026-10-10 10:00');

        $this->assertSame('none', $p['attempts']['A1']['repeat']);
        $this->assertSame('immediate', $p['attempts']['SAME-SESSION']['repeat']);
        $this->assertSame('immediate', $p['attempts']['UNDER-A-DAY']['repeat']);
        $this->assertSame('immediate', $p['attempts']['AFTER-TEACHING']['repeat']);
        $this->assertSame('delayed', $p['attempts']['DELAYED']['repeat']);
    }

    public function test_an_uncertain_time_is_a_guaranteed_gap_so_it_never_makes_a_repeat_delayed_early(): void
    {
        // Day precision: the attempt could have been at 23:59, so the gap to
        // an attempt the next morning is not guaranteed to be a day.
        $p = $this->base()
            ->attempt('A1', '2026-10-01 12:00', 'TK-1', 'correct', 'auto', ['session' => 'S1', 'precision' => 'day'])
            ->attempt('A2', '2026-10-02 23:00', 'TK-1', 'correct', 'auto', ['session' => 'S2'])
            ->attempt('A3', '2026-10-03 00:00', 'TK-1', 'correct', 'auto', ['session' => 'S3'])
            ->project('2026-10-04 10:00');

        $this->assertSame('immediate', $p['attempts']['A2']['repeat']);
        $this->assertSame('immediate', $p['attempts']['A3']['repeat'], 'A2 at 23:00 is the previous attempt');
    }

    public function test_immediate_repeats_still_count_as_failures(): void
    {
        $p = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-01 10:10', 'TK-1', 'incorrect', 'auto', ['session' => 'S1'])
            ->project('2026-10-02 10:00');

        $this->assertSame('regressed', $p['topics']['T']['flags'][0] ?? null);
        $this->assertTopic($p, 'T', 'developing');
    }

    public function test_a_delayed_repeat_recovers_a_regressed_topic_and_an_immediate_one_does_not(): void
    {
        // X3: regression on day 30, teaching on day 31, the failed task correct on day 33.
        $s = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-30 10:00', 'TK-2', 'incorrect', 'auto', ['session' => 'S2']);
        $immediate = (clone $s)->attempt('A3', '2026-10-30 10:20', 'TK-2', 'correct', 'auto', ['session' => 'S2']);
        $this->assertTopic($immediate->project('2026-10-31 09:00'), 'T', 'developing', ['regressed']);

        $s->exposure('TEACH', '2026-10-31 10:00', ['topic:T'], ['session' => 'S3'])
            ->attempt('A3', '2026-11-02 10:00', 'TK-2', 'correct', 'auto', ['session' => 'S4']);
        $p = $s->project('2026-11-03 10:00');
        $this->assertSame('delayed', $p['attempts']['A3']['repeat']);
        $this->assertTopic($p, 'T', 'working', []);
    }

    public function test_merged_tasks_count_as_one_and_a_rejected_merge_counts_as_two(): void
    {
        // X5.
        $s = $this->base()->task('TK-EX')->exercises('TK-EX', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-03 10:00', 'TK-2', 'correct', 'auto', ['session' => 'S2'])
            ->attempt('A3', '2026-10-03 11:00', 'TK-EX', 'correct', 'auto', ['session' => 'S2', 'form' => 'explain'])
            ->judges('J3', 'A3', ['own_words' => true], '2026-10-03 11:30');
        $this->assertTopic($s->project('2026-10-04 10:00'), 'T', 'secure');

        $s->sameAs('SAME', 'task', 'TK-2', 'TK-1', '2026-10-04 11:00');
        $merged = $s->project('2026-10-05 10:00');
        $this->assertSame('TK-1', $merged['attempts']['A2']['task']);
        $this->assertSame('delayed', $merged['attempts']['A2']['repeat']);
        $this->assertTopic($merged, 'T', 'working');

        $s->learnerReview('NOT-SAME', 'claim:SAME', 'reject', '2026-10-05 11:00');
        $unmerged = $s->project('2026-10-06 10:00');
        $this->assertTopic($unmerged, 'T', 'secure');
        $this->assertSame([['target' => 'task:TK-2', 'entity' => 'task:TK-1']], $unmerged['rejected_pairs']);
    }

    public function test_the_named_survivor_keeps_its_identity_whichever_target_it_is(): void
    {
        $s = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-2', 'correct', 'auto', ['session' => 'S1'])
            ->claim('SAME', 'same_as', ['task:TK-1', 'task:TK-2'], ['survivor' => 'task:TK-1'], method: 'rule', at: '2026-10-02 10:00');

        $this->assertSame('TK-1', $s->project('2026-10-03 10:00')['attempts']['A1']['task']);
    }

    public function test_a_retracted_attempt_is_not_evidence(): void
    {
        $s = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->retract('AM1', 'A1', '2026-10-01 11:00');

        $p = $s->project('2026-10-02 10:00');
        $this->assertArrayNotHasKey('A1', $p['attempts']);
        $this->assertTopic($p, 'T', 'not_started');
    }

    public function test_self_graded_attempts_schedule_practice_but_never_qualify(): void
    {
        // ADR 0003 D2: a key the learner wrote is recorded as judged_by self.
        $p = $this->base()
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'self', ['session' => 'S1', 'form' => 'recall'])
            ->project('2026-10-02 10:00');

        $this->assertSame('correct', $p['attempts']['A1']['overall']);
        $this->assertTopic($p, 'T', 'developing');
    }
}
