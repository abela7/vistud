<?php

namespace Tests\Unit\Brain\Projection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Brain\Projection\Support\GoldenScenario;
use Tests\Unit\Brain\Projection\Support\ProjectionAssertions;
use Tests\Unit\Brain\Projection\Support\Scenario;

/**
 * The golden replay (docs/specs/golden-replay-sql-joins.md) run as a WP4
 * developer check. The independent acceptance tests are work package V1's.
 *
 * The expectations include the PM's rulings on the WP4 review, which the
 * spec now states too (PM-approved clarifications):
 * - WP4-Q1 `underconfident` on T-LEFT from CP6: E2 (confused about T-LEFT)
 *   is followed by two qualifying topic successes, E6 and E7, even though
 *   their overall outcomes are incorrect.
 * - WP4-Q2 `practised` where three sessions span at least 21 days within the
 *   current window: from CP10, and no longer on T-LFILTER after its
 *   regression at E16.
 * - WP4-Q3 A5: `needs_review` needs a gap strictly longer than 60 days from
 *   the latest contact's upper bound: false at exactly 18:05, true after.
 */
class GoldenScenarioDevTest extends TestCase
{
    use ProjectionAssertions;

    /** @return iterable<string, array{0: int, 1: string, 2: array, 3: array, 4: array}> */
    public static function checkpoints(): iterable
    {
        $intro = ['T-JOIN' => ['introduced', []], 'T-INNER' => ['introduced', []], 'T-NULL' => ['not_started', []]];

        yield 'CP1' => [35, '2026-10-13 11:05', $intro + ['T-LEFT' => ['introduced', []], 'T-LFILTER' => ['introduced', []]], [], []];
        yield 'CP2' => [39, '2026-10-13 12:30', ['T-LEFT' => ['introduced', ['exam_relevant']]], [], []];
        yield 'CP3' => [44, '2026-10-13 19:41', [], [], ['Q1' => ['open', 0]]];
        yield 'CP4' => [47, '2026-10-13 20:14', ['T-LEFT' => ['introduced', ['claimed_only', 'exam_relevant']]], [], ['Q1' => ['resolved_learner_confirmed', 0]]];
        yield 'CP5' => [49, '2026-10-15 14:21', ['T-LFILTER' => ['developing', []], 'T-LEFT' => ['developing', ['exam_relevant']]], ['M1' => null], []];
        yield 'CP6' => [52, '2026-10-15 14:50', [
            'T-LEFT' => ['working', ['weak_part', 'includes_ai_judged', 'exam_relevant', 'underconfident']],
            'T-LFILTER' => ['developing', []],
        ], ['M1' => ['recurring', 0]], []];
        yield 'CP7' => [61, '2026-10-15 15:52', [
            'T-LFILTER' => ['developing', []],
            'T-LEFT' => ['working', ['weak_part', 'exam_relevant', 'underconfident']],
        ], ['M1' => ['addressed', 0]], ['Q2' => ['answered', 0]]];
        yield 'CP8' => [64, '2026-10-15 16:15', [
            'T-LFILTER' => ['developing', []],
            'T-LEFT' => ['working', ['weak_part', 'exam_relevant', 'underconfident']],
        ], ['M1' => ['addressed', 0]], ['Q2' => ['answered', 0]]];
        yield 'CP9' => [74, '2026-10-22 19:42', [
            'T-LFILTER' => ['secure', ['includes_ai_judged']],
            'T-LEFT' => ['secure', ['includes_ai_judged', 'exam_relevant', 'underconfident']],
        ], ['M1' => ['apparently_resolved', 0]], ['Q1' => ['resolved_demonstrated', 0], 'Q2' => ['resolved_demonstrated', 0]]];
        yield 'CP10' => [76, '2026-11-17 18:35', [
            'T-LFILTER' => ['durable', ['practised']],
            'T-LEFT' => ['durable', ['exam_relevant', 'practised', 'underconfident']],
        ], ['M1' => ['resolved_retained', 0]], []];
        yield 'CP11' => [76, '2027-01-20 12:00', [
            'T-LFILTER' => ['durable', ['needs_review', 'practised']],
            'T-LEFT' => ['durable', ['exam_relevant', 'needs_review', 'practised', 'underconfident']],
        ], [], []];
        yield 'CP12' => [79, '2027-02-03 19:43', [
            'T-LFILTER' => ['developing', ['regressed']],
            'T-LEFT' => ['durable', ['weak_part', 'exam_relevant', 'practised', 'underconfident']],
        ], ['M1' => ['resolved_retained', 0]], []];
        yield 'CP13' => [86, '2027-02-03 20:47', [
            'T-LFILTER' => ['developing', ['regressed']],
            'T-LEFT' => ['durable', ['weak_part', 'exam_relevant', 'practised', 'underconfident']],
        ], ['M1' => ['addressed', 1]], ['Q1' => ['resolved_demonstrated', 0], 'Q2' => ['answered', 1]]];
    }

    #[DataProvider('checkpoints')]
    public function test_checkpoint(int $position, string $now, array $topics, array $misconceptions, array $questions): void
    {
        $p = GoldenScenario::build()->project($now, $position);

        $this->assertSame($position, $p['position']);
        foreach ($topics as $topic => [$label, $flags]) {
            $this->assertTopic($p, $topic, $label, $flags);
        }
        foreach ($misconceptions as $id => $state) {
            $this->assertMisconception($p, $id, $state[0] ?? null, $state[1] ?? null);
        }
        foreach ($questions as $id => [$state, $resurfaced]) {
            $this->assertQuestion($p, $id, $state, resurfaced: $resurfaced);
        }
    }

    public function test_a4_what_is_unresolved_at_checkpoint_13(): void
    {
        $p = GoldenScenario::build()->project('2027-02-03 20:47', 86);

        $this->assertMisconception($p, 'M1', 'addressed', 1);
        $this->assertQuestion($p, 'Q2', 'answered');
        $this->assertHasFlag($p, 'T-LFILTER', 'regressed');
        $this->assertTopic($p, 'T-NULL', 'not_started');
    }

    public function test_the_learning_profile_groups_effect_verdicts_by_approach(): void
    {
        $profile = GoldenScenario::build()->project('2027-02-03 20:47')['profile'];

        $this->assertSame(['analogy', 'contrast', 'visual', 'worked_example'], array_keys($profile));
        $this->assertSame(['helped' => 1, 'no_effect' => 0, 'confused' => 0, 'examples' => ['question:Q1']], $profile['analogy']);
        $this->assertSame(['helped' => 1, 'no_effect' => 0, 'confused' => 0, 'examples' => ['misconception:M1']], $profile['contrast']);
    }

    public function test_a5_how_understanding_changed_in_the_current_view(): void
    {
        $s = GoldenScenario::build();
        $at = fn (string $t) => $s->project($t, observedUntil: $t)['topics']['T-LFILTER'];

        $this->assertSame('introduced', $at('2026-10-13 10:00')['label']);
        $this->assertSame('developing', $at('2026-10-15 14:12')['label']);
        $this->assertSame('working', $at('2026-10-22 19:05')['label']);
        $this->assertSame('secure', $at('2026-10-22 19:12')['label']);
        $this->assertSame('durable', $at('2026-11-17 18:05')['label']);
        // WP4-Q3: E14's interval ends at 18:05 on 17 Nov; 60 days later is 18:05 on 16 Jan.
        $this->assertNotContains('needs_review', $at('2027-01-16T18:04:59+00:00')['flags'], 'before');
        $this->assertNotContains('needs_review', $at('2027-01-16T18:05:00+00:00')['flags'], 'exactly at');
        $this->assertContains('needs_review', $at('2027-01-16T18:05:01+00:00')['flags'], 'immediately after');
        $this->assertSame(['developing', ['regressed']], [$at('2027-02-03 19:42')['label'], $at('2027-02-03 19:42')['flags']]);
        // The belief view never showed "working": it jumped from CP8 to CP9.
        $this->assertSame('developing', $s->project('2026-10-22 19:41', 72)['topics']['T-LFILTER']['label']);
    }

    public function test_v1_undoing_an_automatic_question_match(): void
    {
        $p = GoldenScenario::build()
            ->learnerReview('C26', 'claim:C23', 'reject', '2027-02-03 21:00')
            ->question('C27', 'Q3', '2027-02-03 21:00')
            ->refersTo('C28', 'E18', 'Q3', '2027-02-03 21:00')
            ->project('2027-02-03 21:00');

        $this->assertQuestion($p, 'Q2', 'resolved_demonstrated', resurfaced: 0);
        $this->assertQuestion($p, 'Q3', 'being_answered');
        $this->assertSame([['target' => 'event:E18', 'entity' => 'question:Q2']], $p['rejected_pairs']);
    }

    public function test_v2_a_late_event_known_only_to_the_day(): void
    {
        $all = GoldenScenario::build()->specs();
        $s = Scenario::make();
        foreach ($all as $i => $spec) {
            if ($i + 1 >= 75) {
                break;
            }
            if (! in_array($i + 1, [69, 70, 71, 72, 74], true)) {
                $s->add($spec);
            }
        }
        $this->assertTopic($s->project('2026-10-22 19:42', $s->position('C18')), 'T-LFILTER', 'working');
        $this->assertMisconception($s->project('2026-10-22 19:42'), 'M1', 'apparently_resolved');

        foreach ([68, 69, 70] as $i) {
            $s->add($all[$i]);
        }
        $e13 = array_replace($all[71], [
            'occurred_at' => $s->time('2026-10-22 00:00'), 'precision' => 'day', 'session' => 'S9', 'recorded_at' => $s->time('2026-10-23 08:30'),
        ]);
        $s->add($e13)->add($all[73]);
        $p = $s->project('2026-10-23 09:00');
        $this->assertTopic($p, 'T-LFILTER', 'secure');
        $this->assertSame(['E6', 'E7', 'E10', 'E11', 'E13', 'E12'], array_keys($p['attempts']), 'E13 sorts to the start of its day');

        foreach ([74, 75] as $i) {
            $s->add($all[$i]);
        }
        $this->assertTopic($s->project('2026-11-17 18:35'), 'T-LFILTER', 'durable');
    }

    public function test_v4_the_retention_boundary_with_a_day_precision_import(): void
    {
        $all = GoldenScenario::build()->specs();
        $s = Scenario::make();
        foreach (array_slice($all, 0, 74) as $spec) {
            $s->add($spec);
        }
        $s->task('TK-LMS1', '2026-11-14 10:00')->exercises('TK-LMS1', 'T-LEFT', 'T-LFILTER')
            ->attempt('E-LMS', '2026-11-12 12:00', 'TK-LMS1', 'correct', 'auto', ['precision' => 'day', 'session' => 'S-LMS']);
        foreach (array_slice($all, 74, 2) as $spec) {
            $s->add($spec);
        }

        $p = $s->project('2026-11-17 18:35');
        $this->assertTopic($p, 'T-LFILTER', 'secure');
        $this->assertMisconception($p, 'M1', 'apparently_resolved');
    }

    public function test_v5_a_resolved_question_reopened_by_a_self_report(): void
    {
        $s = Scenario::make();
        foreach (array_slice(GoldenScenario::build()->specs(), 0, 74) as $spec) {
            $s->add($spec);
        }
        $s->selfReport('E20', '2026-10-23 21:00', 'unsure', ['question:Q2'], 'S9');
        $this->assertQuestion($s->project('2026-10-23 21:01'), 'Q2', 'open', ['reopened'], 1);

        $s->exposure('E21', '2026-10-23 21:02', ['topic:T-LFILTER'], ['format' => 'feedback', 'by' => 'chat_ai', 'responds_to' => 'E20', 'session' => 'S9']);
        $p = $s->project('2026-10-23 21:03');
        $this->assertQuestion($p, 'Q2', 'being_answered', ['reopened'], 1);
        $this->assertTopic($p, 'T-LFILTER', 'secure');
    }
}
