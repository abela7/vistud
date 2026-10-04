<?php

namespace Tests\Unit\Brain\Projection;

use App\Brain\Journal\Interval;
use App\Brain\Journal\Precision;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Brain\Projection\Support\ProjectionAssertions;
use Tests\Unit\Brain\Projection\Support\Scenario;

/** ADR 0002 §3 intervals and §7 retention, practice and review. Uncertainty never earns the benefit. */
class TimeRulesTest extends TestCase
{
    use ProjectionAssertions;

    public function test_intervals_follow_the_learners_local_day_and_iso_week_across_the_clock_change(): void
    {
        $at = new DateTimeImmutable('2026-10-25T12:00:00+00:00');
        $day = Interval::compute($at, null, Precision::Day, 'Europe/London');
        $week = Interval::compute($at, null, Precision::Week, 'Europe/London');
        $minute = Interval::compute(new DateTimeImmutable('2026-10-13T10:00:42+01:00'), null, Precision::Minute, 'Europe/London');

        $this->assertSame(25 * 3600.0, $day->hi - $day->lo, 'Sun 25 Oct 2026 has 25 hours in London');
        $this->assertSame('2026-10-18T23:00:00+00:00', Interval::toDateTime($week->lo)->format(DATE_ATOM), 'Monday 19 Oct, 00:00 BST');
        $this->assertSame('2026-10-26T00:00:00+00:00', Interval::toDateTime($week->hi)->format(DATE_ATOM), 'Monday 26 Oct, 00:00 GMT');
        $this->assertSame(60.0, $minute->hi - $minute->lo);
    }

    public function test_events_are_ordered_by_the_start_of_their_interval_then_by_position(): void
    {
        // V2: a late event known only to the day sorts to the start of that day.
        $p = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->exercises('TK-1', 'T')->exercises('TK-2', 'T')
            ->attempt('EVENING', '2026-10-22 19:05', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('SOMETIME', '2026-10-22 12:00', 'TK-2', 'correct', 'auto', ['session' => 'S2', 'precision' => 'day'])
            ->project('2026-10-23 10:00');

        $this->assertSame(['SOMETIME', 'EVENING'], array_keys($p['attempts']));
    }

    public function test_retained_needs_every_earlier_contact_at_least_21_days_before_as_a_guaranteed_gap(): void
    {
        // X4: solved on day 1 and day 25 with nothing in between.
        $s = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->task('TK-EX')->exercises('TK-1', 'T')->exercises('TK-2', 'T')->exercises('TK-EX', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-25 10:00', 'TK-2', 'correct', 'auto', ['session' => 'S2']);
        $this->assertTrue($s->project('2026-10-26 10:00')['topics']['T']['facts']['retained']);
        $this->assertTopic($s->project('2026-10-26 10:00'), 'T', 'durable');

        // One day short: 20 days 23 hours.
        $short = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->exercises('TK-1', 'T')->exercises('TK-2', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-22 09:00', 'TK-2', 'correct', 'auto', ['session' => 'S2']);
        $this->assertFalse($short->project('2026-10-23 10:00')['topics']['T']['facts']['retained']);
    }

    public function test_a_day_precision_contact_is_measured_from_the_end_of_its_day(): void
    {
        // V4: the import could have been late on 12 Nov, so the gap from it
        // is measured from 00:00 on 13 Nov.
        $s = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->exercises('TK-1', 'T')->exercises('TK-2', 'T')
            ->attempt('A1', '2026-10-22 19:12', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('IMPORT', '2026-11-12 12:00', 'TK-2', 'correct', 'auto', ['session' => 'S2', 'precision' => 'day']);

        $p = $s->project('2026-11-14 10:00');
        $this->assertFalse($p['topics']['T']['facts']['retained'], '00:00 on 12 Nov minus 19:12 on 22 Oct is under 21 days');

        $exact = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->exercises('TK-1', 'T')->exercises('TK-2', 'T')
            ->attempt('A1', '2026-10-22 19:12', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('IMPORT', '2026-11-12 20:00', 'TK-2', 'correct', 'auto', ['session' => 'S2']);
        $this->assertTrue($exact->project('2026-11-14 10:00')['topics']['T']['facts']['retained'], 'That evening, it would have passed');
    }

    public function test_weekly_practice_is_practised_but_never_retained(): void
    {
        // X4, second half: every practice session is itself a contact.
        $s = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->exercises('TK-1', 'T')->exercises('TK-2', 'T');
        foreach (['2026-10-01', '2026-10-08', '2026-10-15', '2026-10-22'] as $i => $day) {
            $s->attempt("A{$i}", "{$day} 10:00", $i % 2 === 0 ? 'TK-1' : 'TK-2', 'correct', 'auto', ['session' => "S{$i}"]);
        }

        $p = $s->project('2026-10-23 10:00');
        $this->assertFalse($p['topics']['T']['facts']['retained']);
        $this->assertHasFlag($p, 'T', 'practised');
    }

    public function test_needs_review_starts_once_the_gap_is_longer_than_the_review_interval(): void
    {
        $s = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->task('TK-EX')->exercises('TK-1', 'T')->exercises('TK-2', 'T')->exercises('TK-EX', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-02 10:00', 'TK-2', 'correct', 'auto', ['session' => 'S2'])
            ->attempt('A3', '2026-10-02 11:00', 'TK-EX', 'correct', 'auto', ['session' => 'S2', 'form' => 'explain'])
            ->judges('J3', 'A3', ['own_words' => true], '2026-10-02 11:30');

        // The last contact ended at 10:00 UTC on 2 Oct (11:00 BST). "Longer than"
        // 30 days: exactly 30 days is not yet (see the WP4 question on the A5 boundary).
        $this->assertTopic($s->project('2026-11-01T10:00:00+00:00'), 'T', 'secure', []);
        $this->assertTopic($s->project('2026-11-01T10:01:00+00:00'), 'T', 'secure', ['needs_review']);
    }

    public function test_the_label_can_rise_only_on_evidence_that_had_occurred_by_then_in_the_current_view(): void
    {
        $s = Scenario::make()->topic('T')->task('TK-1')->exercises('TK-1', 'T')
            ->attempt('A1', '2026-10-01 10:00', 'TK-1', 'incorrect', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-05 10:00', 'TK-1', 'correct', 'auto', ['session' => 'S2']);

        $this->assertTopic($s->project('2026-10-03 10:00', observedUntil: '2026-10-03 10:00'), 'T', 'developing');
        $this->assertTopic($s->project('2026-10-06 10:00'), 'T', 'working');
    }

    public function test_the_review_boundary_is_strict_and_measured_from_the_contacts_upper_bound(): void
    {
        // PM ruling WP4-Q3: at exactly the interval, false; immediately after, true.
        $lecture = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->task('TK-EX')->exercises('TK-1', 'T')->exercises('TK-2', 'T')->exercises('TK-EX', 'T')
            ->attempt('A1', '2026-10-01T10:00:00+00:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-10-02T10:00:00+00:00', 'TK-2', 'correct', 'auto', ['session' => 'S2'])
            ->attempt('A3', '2026-10-02T10:30:00+00:00', 'TK-EX', 'correct', 'auto', ['session' => 'S2', 'form' => 'explain'])
            ->judges('J3', 'A3', ['own_words' => true], '2026-10-02T10:40:00+00:00')
            // A two-hour lecture: its interval ends at 14:00.
            ->exposure('LECTURE', '2026-10-02T12:00:00+00:00', ['topic:T'], ['format' => 'lecture', 'until' => '2026-10-02T14:00:00+00:00']);

        $flags = fn (string $now) => $lecture->project($now)['topics']['T']['flags'];
        $this->assertSame([], $flags('2026-11-01T13:59:59+00:00'), 'Before the boundary');
        $this->assertSame([], $flags('2026-11-01T14:00:00+00:00'), 'Exactly 30 days after the upper bound');
        $this->assertSame(['needs_review'], $flags('2026-11-01T14:00:01+00:00'), 'Immediately after');

        // A day-precision contact ends at the end of its local day.
        $day = Scenario::make()->topic('T')->task('TK-1')->task('TK-2')->task('TK-EX')->exercises('TK-1', 'T')->exercises('TK-2', 'T')->exercises('TK-EX', 'T')
            ->attempt('A1', '2026-11-01T10:00:00+00:00', 'TK-1', 'correct', 'auto', ['session' => 'S1'])
            ->attempt('A2', '2026-11-02T10:00:00+00:00', 'TK-2', 'correct', 'auto', ['session' => 'S2'])
            ->attempt('A3', '2026-11-03T10:30:00+00:00', 'TK-EX', 'correct', 'auto', ['session' => 'S3', 'form' => 'explain', 'precision' => 'day'])
            ->judges('J3', 'A3', ['own_words' => true], '2026-11-03T10:40:00+00:00');
        $this->assertSame([], $day->project('2026-12-04T00:00:00+00:00')['topics']['T']['flags']);
        $this->assertSame(['needs_review'], $day->project('2026-12-04T00:00:01+00:00')['topics']['T']['flags']);
    }
}
