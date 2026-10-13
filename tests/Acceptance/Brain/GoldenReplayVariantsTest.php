<?php

namespace Tests\Acceptance\Brain;

use PHPUnit\Framework\TestCase;
use Tests\Acceptance\Brain\Entries as E;

/**
 * Variants V1, V2, V4 and V5 of docs/specs/golden-replay-sql-joins.md. V3
 * (redaction) needs the database and the redaction service.
 */
class GoldenReplayVariantsTest extends TestCase
{
    /** @return list<array> the fixture's entries at positions $from to $to */
    private static function positions(int $from, int $to): array
    {
        return array_slice(GoldenReplay::specs(), $from - 1, $to - $from + 1);
    }

    private static function journal(array ...$parts): InMemoryJournal
    {
        $journal = new InMemoryJournal;
        foreach (array_merge(...$parts) as $spec) {
            $journal->append($spec);
        }

        return $journal;
    }

    /** V1: the learner rejects the automatic match of E18 to Q2, and E18 is matched to a new Q3. */
    public function test_v1_undoing_an_automatic_question_match(): void
    {
        $at = '2027-02-03T21:00:00+00:00';
        $journal = self::journal(self::positions(1, 86), [
            E::review('C26', $at, 'claim:C23', 'reject', 'wrong_entity'),
            E::claim('C27', $at, 'defines', ['question:Q3'], ['entity_type' => 'question', 'status' => 'active'], E::rule('question-resolver')) + ['content' => ['phrasing' => 'Why a WHERE date condition drops rows.']],
            E::claim('C28', $at, 'refers_to', ['event:E18'], ['entity' => 'question:Q3'], E::rule('question-resolver'), 1.0),
        ]);

        $snapshot = $journal->project('2027-02-03T21:00:00+00:00');

        $this->assertSame('resolved_demonstrated', $snapshot['questions']['Q2']['state']);
        $this->assertSame(0, $snapshot['questions']['Q2']['resurfaced_count']);
        $this->assertSame('being_answered', $snapshot['questions']['Q3']['state']);
        $this->assertContains(['target' => 'event:E18', 'entity' => 'question:Q2'], $snapshot['rejected_pairs']);
    }

    /**
     * V2: E13 arrives late, known only to the day. Positions 69–72 and 74 are
     * removed and appended after position 74, recorded on Fri 23 Oct.
     */
    public function test_v2_an_event_that_arrives_late_known_only_to_the_day(): void
    {
        $original = array_column(GoldenReplay::specs(), null, 'id');
        $late = fn (string $id, string $at) => ['occurred_at' => $at] + $original[$id];
        $e13 = ['occurred_at' => '2026-10-22T00:00:00+01:00', 'precision' => 'day', 'recorded_at' => '2026-10-23T08:30:00+01:00', 'session' => 'S9'] + $original['E13'];

        $journal = self::journal(self::positions(1, 68), [$original['C18']], [
            $late('TK-C2', '2026-10-23T08:29:00+01:00'),
            $late('K27', '2026-10-23T08:29:00+01:00'),
            $late('K28', '2026-10-23T08:29:00+01:00'),
            $e13,
            $late('C19', '2026-10-23T09:00:00+01:00'),
        ], self::positions(75, 86));

        $atC18 = $journal->project('2026-10-22T19:42:00+01:00', maxPosition: 69);
        $this->assertSame('working', $atC18['topics']['T-LFILTER']['label']);
        $this->assertSame('apparently_resolved', $atC18['misconceptions']['M1']['state']);

        $afterC19 = $journal->project('2026-10-23T09:00:00+01:00', maxPosition: 74);
        $this->assertSame('secure', $afterC19['topics']['T-LFILTER']['label']);
        $this->assertSame(['E6', 'E7', 'E10', 'E11', 'E13', 'E12'], array_keys($afterC19['attempts']), 'E13 is placed at the start of its day');
        $this->assertFalse($afterC19['attempts']['E13']['misconceptions']['M1']);

        $cp10 = $journal->project('2026-11-17T18:35:00+00:00', maxPosition: 76);
        $this->assertSame('durable', $cp10['topics']['T-LFILTER']['label']);
    }

    /** V4: an imported day-precision success is not retained, and it stops E14 being retained. */
    public function test_v4_the_retention_boundary_with_a_day_precision_event(): void
    {
        $at = '2026-11-14T10:00:00+00:00';
        $journal = self::journal(self::positions(1, 74), [
            E::task('TK-LMS1', 'lms/TK-LMS1', $at),
            E::relates('K29', $at, 'task:TK-LMS1', 'exercises', 'topic:T-LEFT'),
            E::relates('K30', $at, 'task:TK-LMS1', 'exercises', 'topic:T-LFILTER'),
            E::attempt('E-LMS1', '2026-11-12T00:00:00+00:00', 'S-LMS', 'TK-LMS1', 'correct', setting: 'practice', extra: [
                'origin' => 'reported', 'precision' => 'day', 'recorded_at' => $at,
                'body' => ['checker' => ['id' => 'school-lms', 'version' => 1, 'key_source' => 'instructor']],
            ]),
        ], self::positions(75, 76));

        $cp10 = $journal->project('2026-11-17T18:35:00+00:00');

        $this->assertSame('secure', $cp10['topics']['T-LFILTER']['label']);
        $this->assertFalse($cp10['topics']['T-LFILTER']['facts']['retained']);
        $this->assertSame('apparently_resolved', $cp10['misconceptions']['M1']['state']);
    }

    /** V5: an uncertain self-report reopens a resolved question; the answer after it moves it on. */
    public function test_v5_a_resolved_question_reopened_by_a_self_report(): void
    {
        $journal = self::journal(self::positions(1, 74), [
            E::selfReport('E20', '2026-10-23T21:00:00+01:00', 'S9', 'unsure', ['question:Q2']),
            E::exposure('E21', '2026-10-23T21:02:00+01:00', 'S9', 'feedback', [], ['type' => 'chat_ai', 'id' => 'chatgpt'], respondsTo: ['E20'], actor: E::CHATGPT),
        ]);

        $afterE20 = $journal->project('2026-10-23T21:00:00+01:00', maxPosition: 75);
        $this->assertSame('open', $afterE20['questions']['Q2']['state']);
        $this->assertContains('reopened', $afterE20['questions']['Q2']['flags']);
        $this->assertSame(1, $afterE20['questions']['Q2']['resurfaced_count']);
        $this->assertSame('secure', $afterE20['topics']['T-LFILTER']['label']);

        $afterE21 = $journal->project('2026-10-23T21:02:00+01:00', maxPosition: 76);
        $this->assertSame('being_answered', $afterE21['questions']['Q2']['state']);
        $this->assertSame('secure', $afterE21['topics']['T-LFILTER']['label']);
    }
}
