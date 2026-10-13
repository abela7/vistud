<?php

namespace Tests\Acceptance\Brain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The golden replay of docs/specs/golden-replay-sql-joins.md: positions 1–86
 * fed to the projector, and the belief view checked at every checkpoint.
 *
 * Flags are compared over the flags the spec's checkpoint table speaks about
 * (TRACKED). A flag the spec gives a topic stays until the spec clears it or
 * the topic is listed without it; exam_relevant on T-LEFT comes from an
 * accepted claim nothing ever ends, so it holds from checkpoint 2 on.
 */
class GoldenReplayTest extends TestCase
{
    private const TRACKED = ['claimed_only', 'exam_relevant', 'includes_ai_judged', 'needs_review', 'regressed', 'rests_on_dispute', 'weak_part'];

    private const SHORT = ['JOIN' => 'T-JOIN', 'INNER' => 'T-INNER', 'LEFT' => 'T-LEFT', 'NULL' => 'T-NULL', 'LFILTER' => 'T-LFILTER'];

    private static ?InMemoryJournal $journal = null;

    private static function journal(): InMemoryJournal
    {
        return self::$journal ??= InMemoryJournal::of(GoldenReplay::specs());
    }

    public function test_the_fixture_is_valid_and_lands_at_the_positions_the_spec_gives(): void
    {
        $positions = [];
        foreach (GoldenReplay::specs() as $i => $spec) {
            $positions[$spec['id']] = $i + 1;
        }

        $this->assertSame(86, self::journal()->position());
        foreach (['A-L7' => 1, 'TK-Q3' => 5, 'K1' => 11, 'K6' => 16, 'K11' => 21, 'K14' => 24, 'K24' => 34, 'E1' => 35, 'C2' => 39,
            'C6' => 47, 'E7' => 49, 'C9' => 52, 'C14' => 61, 'C17' => 64, 'TK-C1' => 65, 'E12' => 68, 'C19' => 74, 'C20' => 76,
            'E17' => 79, 'C25' => 86] as $id => $position) {
            $this->assertSame($position, $positions[$id], $id);
        }
    }

    /** @return iterable<string, array{0: int, 1: string, 2: array}> */
    public static function checkpoints(): iterable
    {
        // [cp, position, now (local, with offset), changes]. Topic changes are [label, flags].
        $table = [
            [1, 35, '2026-10-13T11:05:00+01:00', ['topics' => [
                'JOIN' => ['introduced', []], 'INNER' => ['introduced', []], 'LEFT' => ['introduced', []],
                'LFILTER' => ['introduced', []], 'NULL' => ['not_started', []],
            ]]],
            [2, 39, '2026-10-13T12:30:00+01:00', ['topics' => ['LEFT' => ['introduced', ['exam_relevant']]]]],
            [3, 44, '2026-10-13T19:41:00+01:00', ['Q1' => ['open', 0]]],
            [4, 47, '2026-10-13T20:14:00+01:00', [
                'topics' => ['LEFT' => ['introduced', ['claimed_only', 'exam_relevant']]],
                'Q1' => ['resolved_learner_confirmed', 0],
            ]],
            [5, 49, '2026-10-15T14:21:00+01:00', ['topics' => [
                'LFILTER' => ['developing', []], 'LEFT' => ['developing', ['exam_relevant']],
            ]]],
            [6, 52, '2026-10-15T14:50:00+01:00', [
                'topics' => ['LEFT' => ['working', ['exam_relevant', 'includes_ai_judged', 'weak_part']], 'LFILTER' => ['developing', []]],
                'M1' => ['recurring', 0],
            ]],
            [7, 61, '2026-10-15T15:52:00+01:00', [
                'topics' => ['LFILTER' => ['developing', []], 'LEFT' => ['working', ['exam_relevant', 'weak_part']]],
                'M1' => ['addressed', 0],
                'Q2' => ['answered', 0],
            ]],
            [8, 64, '2026-10-15T16:15:00+01:00', [
                'M1' => ['addressed', 0],
                'counters' => ['TK-Q5'],
            ]],
            [9, 74, '2026-10-22T19:42:00+01:00', [
                'topics' => ['LFILTER' => ['secure', ['includes_ai_judged']], 'LEFT' => ['secure', ['exam_relevant', 'includes_ai_judged']]],
                'M1' => ['apparently_resolved', 0],
                'counters' => ['TK-Q5', 'TK-C1', 'TK-C2'],
                'Q1' => ['resolved_demonstrated', 0],
                'Q2' => ['resolved_demonstrated', 0],
            ]],
            [10, 76, '2026-11-17T18:35:00+00:00', [
                'topics' => ['LFILTER' => ['durable', []], 'LEFT' => ['durable', ['exam_relevant']]],
                'M1' => ['resolved_retained', 0],
            ]],
            [11, 76, '2027-01-20T12:00:00+00:00', ['topics' => [
                'LFILTER' => ['durable', ['needs_review']], 'LEFT' => ['durable', ['exam_relevant', 'needs_review']],
            ]]],
            [12, 79, '2027-02-03T19:43:00+00:00', [
                'topics' => ['LFILTER' => ['developing', ['regressed']], 'LEFT' => ['durable', ['exam_relevant', 'weak_part']]],
                'M1' => ['resolved_retained', 0],
            ]],
            [13, 86, '2027-02-03T20:47:00+00:00', [
                'topics' => ['LFILTER' => ['developing', ['regressed']], 'LEFT' => ['durable', ['exam_relevant', 'weak_part']]],
                'M1' => ['addressed', 1],
                'Q1' => ['resolved_demonstrated', 0],
                'Q2' => ['answered', 1],
            ]],
        ];

        $state = ['topics' => [], 'M1' => null, 'Q1' => null, 'Q2' => null];
        foreach ($table as [$cp, $position, $now, $changes]) {
            foreach ($changes['topics'] ?? [] as $topic => $expected) {
                $state['topics'][$topic] = $expected;
            }
            foreach (['M1', 'Q1', 'Q2'] as $key) {
                if (isset($changes[$key])) {
                    $state[$key] = $changes[$key];
                }
            }

            yield "CP{$cp}" => [$position, $now, $state + ['counters' => $changes['counters'] ?? null]];
        }
    }

    /** Each checkpoint shows exactly the state the spec's table gives it. */
    #[DataProvider('checkpoints')]
    public function test_checkpoint(int $position, string $now, array $expected): void
    {
        $snapshot = self::journal()->project($now, maxPosition: $position);

        $this->assertSame($position, $snapshot['position']);
        $this->assertSame($this->expectedTopics($expected['topics']), $this->topics($snapshot));
        $this->assertSame(
            $expected['M1'] === null ? null : ['state' => $expected['M1'][0], 'resurfaced_count' => $expected['M1'][1]],
            isset($snapshot['misconceptions']['M1']) ? array_intersect_key($snapshot['misconceptions']['M1'], ['state' => 0, 'resurfaced_count' => 0]) : null,
            'M1',
        );
        foreach (['Q1', 'Q2'] as $q) {
            $this->assertSame(
                $expected[$q] === null ? null : ['state' => $expected[$q][0], 'resurfaced_count' => $expected[$q][1]],
                isset($snapshot['questions'][$q]) ? array_intersect_key($snapshot['questions'][$q], ['state' => 0, 'resurfaced_count' => 0]) : null,
                $q,
            );
        }
        if ($expected['counters'] !== null) {
            $this->assertSame($expected['counters'], $this->counterTasks($snapshot), 'M1 counters');
        }
    }

    /** Checkpoint 8: E11 repeats TK-Q3 on the day of E6 and 23 minutes after teaching, so it is immediate. */
    public function test_an_immediate_repeat_is_classified_as_one(): void
    {
        $attempts = self::journal()->project('2026-10-15T16:15:00+01:00', maxPosition: 64)['attempts'];

        $this->assertSame('immediate', $attempts['E11']['repeat']);
        $this->assertSame('none', $attempts['E10']['repeat']);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: list<string>}> */
    public static function currentView(): iterable
    {
        yield 'Tue 13 Oct 10:00' => ['2026-10-13T10:00:00+01:00', 'introduced', []];
        yield 'Thu 15 Oct 14:12' => ['2026-10-15T14:12:00+01:00', 'developing', []];
        yield 'Thu 22 Oct 19:05' => ['2026-10-22T19:05:00+01:00', 'working', []];
        yield 'Thu 22 Oct 19:12' => ['2026-10-22T19:12:00+01:00', 'secure', []];
        yield 'Tue 17 Nov 18:05' => ['2026-11-17T18:05:00+00:00', 'durable', []];
        yield 'Sat 16 Jan 18:05, exactly 60 days' => ['2027-01-16T18:05:00+00:00', 'durable', []];
        yield 'Sat 16 Jan 18:06' => ['2027-01-16T18:06:00+00:00', 'durable', ['needs_review']];
        yield 'Wed 3 Feb 19:42' => ['2027-02-03T19:42:00+00:00', 'developing', ['regressed']];
    }

    /** A5: in the current view (every claim, observations up to the moment) LFILTER passes through working. */
    #[DataProvider('currentView')]
    public function test_lfilter_in_the_current_view(string $at, string $label, array $flags): void
    {
        $topic = self::journal()->project($at, observedUntil: $at)['topics']['T-LFILTER'];

        $this->assertSame($label, $topic['label']);
        $this->assertSame($flags, array_values(array_intersect($topic['flags'], ['needs_review', 'regressed'])));
    }

    /** A4: what is unresolved at checkpoint 13. */
    public function test_what_is_unresolved_at_checkpoint_13(): void
    {
        $snapshot = self::journal()->project('2027-02-03T20:47:00+00:00', maxPosition: 86);

        $this->assertContains($snapshot['misconceptions']['M1']['state'], ['detected', 'recurring', 'resurfaced', 'addressed']);
        $this->assertSame('answered', $snapshot['questions']['Q2']['state']);
        $this->assertContains('regressed', $snapshot['topics']['T-LFILTER']['flags']);
        $this->assertSame('not_started', $snapshot['topics']['T-NULL']['label']);
    }

    private function expectedTopics(array $topics): array
    {
        $out = [];
        foreach (self::SHORT as $short => $id) {
            [$label, $flags] = $topics[$short];
            sort($flags);
            $out[$id] = ['label' => $label, 'flags' => $flags];
        }
        ksort($out);

        return $out;
    }

    private function topics(array $snapshot): array
    {
        $out = [];
        foreach (self::SHORT as $id) {
            $topic = $snapshot['topics'][$id] ?? ['label' => 'absent', 'flags' => []];
            $out[$id] = ['label' => $topic['label'], 'flags' => array_values(array_intersect($topic['flags'], self::TRACKED))];
        }
        ksort($out);

        return $out;
    }

    /** Tasks of M1's counters: after its latest exhibit, M1 absent, and a qualifying success on T-LFILTER. */
    private function counterTasks(array $snapshot): array
    {
        $tasks = [];
        foreach (array_reverse($snapshot['attempts'], true) as $attempt) {
            if (($attempt['misconceptions']['M1'] ?? null) === true) {
                break;
            }
            if (($attempt['misconceptions']['M1'] ?? null) === false && $attempt['repeat'] !== 'immediate'
                && ($attempt['topics']['T-LFILTER'] ?? null) === 'correct') {
                $tasks[] = $attempt['task'];
            }
        }

        return array_values(array_unique(array_reverse($tasks)));
    }
}
