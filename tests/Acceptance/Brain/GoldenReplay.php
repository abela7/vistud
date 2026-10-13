<?php

namespace Tests\Acceptance\Brain;

use Tests\Acceptance\Brain\Entries as E;

/**
 * The SQL JOINs fixture of docs/specs/golden-replay-sql-joins.md, positions
 * 1–86, as entry specifications. Times are local Europe/London with their
 * offset: +01:00 until the clocks go back on 25 Oct 2026, +00:00 after.
 */
final class GoldenReplay
{
    public const TOPICS = ['T-JOIN', 'T-INNER', 'T-LEFT', 'T-NULL', 'T-LFILTER'];

    private const SETUP = '2026-10-12T20:00:00+01:00';

    /** @return list<array> in position order: index 0 is position 1 */
    public static function specs(): array
    {
        $specs = [...self::setup(), ...self::timeline()];
        foreach ($specs as $i => $spec) {
            $specs[$i]['tz'] = 'Europe/London';
        }

        return $specs;
    }

    /** @return list<array> positions 1–34, Mon 12 Oct 2026 20:00, by the learner */
    private static function setup(): array
    {
        $at = self::SETUP;
        $activity = fn (string $id, string $kind, string $title, array $times = []) => [
            'id' => $id, 'kind' => 'record', 'actor' => E::LEARNER, 'occurred_at' => $at,
            'body' => ['record_type' => 'activity', 'record_id' => $id, 'kind' => $kind, 'title' => $title] + $times,
        ];

        $specs = [
            $activity('A-L7', 'lecture', 'Lecture 7', ['starts_at' => '2026-10-13T10:00:00+01:00', 'ends_at' => '2026-10-13T11:00:00+01:00']),
            $activity('A-LAB4', 'lab', 'Lab 4', ['starts_at' => '2026-10-15T14:00:00+01:00', 'ends_at' => '2026-10-15T16:00:00+01:00']),
            $activity('A-Q1711', 'quiz', 'Quiz', ['starts_at' => '2026-11-17T00:00:00+00:00']),
            $activity('A-P25', 'other', 'Past paper 2025'),
            E::task('TK-Q3', 'A-LAB4/q3', $at, 'A-LAB4'),
            E::task('TK-Q4', 'A-LAB4/q4', $at, 'A-LAB4'),
            E::task('TK-Q5', 'A-LAB4/q5', $at, 'A-LAB4'),
            E::task('TK-QZ2', 'A-Q1711/q2', $at, 'A-Q1711'),
            E::task('TK-P6A', 'A-P25/q6a', $at, 'A-P25'),
            E::task('TK-P6B', 'A-P25/q6b', $at, 'A-P25'),
        ];
        foreach (self::TOPICS as $i => $topic) {
            $specs[] = E::topic('K'.($i + 1), $topic, $at);
        }

        $relations = [
            ['topic:T-INNER', 'part_of', 'topic:T-JOIN'],
            ['topic:T-LEFT', 'part_of', 'topic:T-JOIN'],
            ['topic:T-LFILTER', 'part_of', 'topic:T-LEFT'],
            ['topic:T-NULL', 'prerequisite_of', 'topic:T-LEFT'],
            ['topic:T-INNER', 'contrasts_with', 'topic:T-LEFT'],
            ['activity:A-L7', 'covers', 'topic:T-JOIN'],
            ['activity:A-L7', 'covers', 'topic:T-INNER'],
            ['activity:A-L7', 'covers', 'topic:T-LEFT'],
        ];
        foreach (['TK-Q3', 'TK-Q4', 'TK-Q5', 'TK-QZ2'] as $task) {
            $relations[] = ["task:{$task}", 'exercises', 'topic:T-LEFT'];
            $relations[] = ["task:{$task}", 'exercises', 'topic:T-LFILTER'];
        }
        $relations[] = ['task:TK-P6A', 'exercises', 'topic:T-LEFT'];
        $relations[] = ['task:TK-P6B', 'exercises', 'topic:T-LEFT'];
        $relations[] = ['task:TK-P6B', 'exercises', 'topic:T-LFILTER'];

        foreach ($relations as $i => [$from, $relation, $to]) {
            $specs[] = E::relates('K'.($i + 6), $at, $from, $relation, $to);
        }

        return $specs;
    }

    /** @return list<array> positions 35–86 */
    private static function timeline(): array
    {
        $resolver = fn (string $id) => E::rule($id);
        $chatAi = ['type' => 'chat_ai', 'id' => 'chatgpt'];
        $capture = ['type' => 'processor', 'id' => 'capture', 'channel' => 'mcp'];
        $tue = fn (string $t) => "2026-10-13T{$t}:00+01:00";
        $thu = fn (string $t) => "2026-10-15T{$t}:00+01:00";
        $thu22 = fn (string $t) => "2026-10-22T{$t}:00+01:00";
        $feb = fn (string $t) => "2027-02-03T{$t}:00+00:00";

        return [
            // 35 E1
            E::exposure('E1', $tue('10:00'), 'S0', 'lecture', ['T-JOIN', 'T-INNER', 'T-LEFT'], ['type' => 'lecturer'],
                extra: ['origin' => 'reported', 'occurred_until' => $tue('11:00'), 'recorded_at' => $tue('11:05'), 'activity' => 'A-L7']),
            // 36 SRC-7, 37 X-7
            ['id' => 'SRC-7', 'kind' => 'record', 'actor' => E::LEARNER, 'occurred_at' => $tue('11:20'),
                'body' => ['record_type' => 'source', 'record_id' => 'SRC-7', 'kind' => 'recording', 'title' => 'Lecture 7 recording', 'storage_key' => 'sources/src-7', 'hash' => 'sha256:src7']],
            ['id' => 'X-7', 'kind' => 'record', 'actor' => ['type' => 'processor', 'id' => 'local-transcriber', 'channel' => 'job'], 'occurred_at' => $tue('11:50'),
                'body' => ['record_type' => 'extraction', 'record_id' => 'X-7', 'source' => 'SRC-7', 'extractor' => 'local-transcriber', 'extractor_version' => '1', 'version' => 1]],
            // 38 C1, 39 C2
            E::relates('C1', $tue('12:00'), 'activity:A-L7', 'emphasizes', 'topic:T-LEFT', E::interpreter('transcript-emphasis@1'), 0.9, 'pending',
                ['derived_from' => ['source:SRC-7#00:34:10']], ['emphasis' => 'exam_relevant']),
            E::review('C2', $tue('12:30'), 'claim:C1', 'accept', 'confirmed'),
            // 40–47
            E::selfReport('E2', $tue('19:40'), 'S1', 'confused', ['topic:T-LEFT', 'topic:T-INNER']),
            E::question('E3', $tue('19:41'), 'S1', 'Why does LEFT JOIN give me rows full of NULLs?', ['topic:T-LEFT']),
            E::claim('C3', $tue('19:41'), 'defines', ['question:Q1'], ['entity_type' => 'question', 'status' => 'active'], $resolver('question-resolver')) + ['content' => ['phrasing' => 'A question the learner asked.']],
            E::claim('C4', $tue('19:41'), 'refers_to', ['event:E3'], ['entity' => 'question:Q1'], $resolver('question-resolver'), 1.0),
            E::relates('C5', $tue('19:41'), 'question:Q1', 'stems_from', 'topic:T-LEFT', $resolver('question-resolver'), 0.86),
            E::exposure('E4', $tue('19:43'), 'S1', 'explanation', ['T-LEFT'], $chatAi, ['analogy', 'worked_example'], ['E3'], actor: E::CHATGPT),
            E::selfReport('E5', $tue('19:44'), 'S1', 'clicked', ['question:Q1'], 'E4'),
            E::claim('C6', $tue('20:14'), 'judges', ['event:E4'], [
                'answer' => [['question' => 'question:Q1', 'adequacy' => 'full']],
                'effect' => [['target' => 'question:Q1', 'effect' => 'helped']],
            ], E::interpreter('judge-exposure@1'), 0.8),
            // 48–52, Thu 15 Oct
            E::attempt('E6', $thu('14:12'), 'S2', 'TK-Q3', 'incorrect'),
            E::attempt('E7', $thu('14:20'), 'S2', 'TK-Q4', 'incorrect'),
            E::claim('C7', $thu('14:50'), 'defines', ['misconception:M1'], ['entity_type' => 'misconception', 'status' => 'active', 'topics' => ['topic:T-LFILTER']],
                E::interpreter('diagnose@1'), 0.8, extra: ['derived_from' => ['event:E6', 'event:E7']]) + ['content' => ['statement' => 'Puts a condition on the right table in WHERE, which drops the unmatched rows.']],
            E::judgesAttempt('C8', $thu('14:50'), 'E6', self::m1Verdict('incorrect', true) + ['cause' => 'misconception']),
            E::judgesAttempt('C9', $thu('14:50'), 'E7', self::m1Verdict('incorrect', true) + ['cause' => 'misconception']),
            // 53–57
            E::question('E8', $thu('15:20'), 'S3', 'Why do customers with no orders disappear from my results?', ['topic:T-LFILTER']),
            E::claim('C10', $thu('15:20'), 'defines', ['question:Q2'], ['entity_type' => 'question', 'status' => 'active'], $resolver('question-resolver')) + ['content' => ['phrasing' => 'A question the learner asked.']],
            E::claim('C11', $thu('15:20'), 'refers_to', ['event:E8'], ['entity' => 'question:Q2'], $resolver('question-resolver'), 1.0),
            E::relates('C12', $thu('15:20'), 'question:Q2', 'stems_from', 'misconception:M1', $resolver('question-resolver'), 0.78),
            E::exposure('E9', $thu('15:22'), 'S3', 'feedback', ['T-LFILTER'], $chatAi, ['contrast', 'visual'], ['E8'], actor: E::CHATGPT),
            // 58–64
            E::attempt('E10', $thu('15:41'), 'S4', 'TK-Q5', 'correct'),
            E::attempt('E11', $thu('15:45'), 'S4', 'TK-Q3', 'correct'),
            E::relates('C13', $thu('15:52'), 'event:E9', 'addresses', 'misconception:M1', E::interpreter('diagnose@1'), 0.85),
            E::claim('C14', $thu('15:52'), 'judges', ['event:E9'], ['answer' => [['question' => 'question:Q2', 'adequacy' => 'full']]], E::interpreter('judge-exposure@1'), 0.75),
            E::judgesAttempt('C15', $thu('16:15'), 'E10', self::m1Verdict('correct', false)),
            E::judgesAttempt('C16', $thu('16:15'), 'E11', ['overall' => 'correct', 'misconceptions' => E::misconception('M1', false)]),
            E::claim('C17', $thu('16:15'), 'judges', ['event:E9'], ['effect' => [['target' => 'misconception:M1', 'effect' => 'helped']]], E::interpreter('judge-exposure@1'), 0.6),
            // 65–74, Thu 22 Oct
            E::task('TK-C1', 'chat/TK-C1', $thu22('19:04'), actor: $capture),
            E::relates('K25', $thu22('19:04'), 'task:TK-C1', 'exercises', 'topic:T-LEFT', $resolver('task-resolver'), 0.9),
            E::relates('K26', $thu22('19:04'), 'task:TK-C1', 'exercises', 'topic:T-LFILTER', $resolver('task-resolver'), 0.9),
            E::attempt('E12', $thu22('19:05'), 'S5', 'TK-C1', 'correct', 'ai', 'explain', 'chat', actor: E::CHATGPT),
            E::task('TK-C2', 'chat/TK-C2', $thu22('19:11'), actor: $capture),
            E::relates('K27', $thu22('19:11'), 'task:TK-C2', 'exercises', 'topic:T-LEFT', $resolver('task-resolver'), 0.9),
            E::relates('K28', $thu22('19:11'), 'task:TK-C2', 'exercises', 'topic:T-LFILTER', $resolver('task-resolver'), 0.9),
            E::attempt('E13', $thu22('19:12'), 'S5', 'TK-C2', 'correct', 'ai', 'apply', 'chat', actor: E::CHATGPT),
            E::judgesAttempt('C18', $thu22('19:42'), 'E12', self::m1Verdict('correct', false) + ['own_words' => true, 'demonstrates' => ['question:Q1', 'question:Q2']]),
            E::judgesAttempt('C19', $thu22('19:42'), 'E13', self::m1Verdict('correct', false)),
            // 75–76, Tue 17 Nov (after the clocks go back)
            E::attempt('E14', '2026-11-17T18:05:00+00:00', 'S6', 'TK-QZ2', 'correct', setting: 'quiz'),
            E::judgesAttempt('C20', '2026-11-17T18:35:00+00:00', 'E14', ['overall' => 'correct', 'misconceptions' => E::misconception('M1', false)]),
            // 77–86, Wed 3 Feb 2027
            E::attempt('E15', $feb('19:35'), 'S7', 'TK-P6A', 'correct', setting: 'practice'),
            E::attempt('E16', $feb('19:42'), 'S7', 'TK-P6B', 'incorrect', setting: 'practice'),
            E::selfReport('E17', $feb('19:43'), 'S7', 'forgot', ['topic:T-LFILTER'], actor: E::LEARNER),
            E::judgesAttempt('C21', $feb('20:13'), 'E15', ['topics' => E::topics(['T-LEFT' => 'correct'])]),
            E::judgesAttempt('C22', $feb('20:13'), 'E16', self::m1Verdict('incorrect', true) + ['cause' => 'forgot'],
                extra: ['derived_from' => ['event:E16', 'event:E17']]),
            E::question('E18', $feb('20:15'), 'S8', 'Why does putting the date condition in WHERE drop rows again?'),
            E::claim('C23', $feb('20:15'), 'refers_to', ['event:E18'], ['entity' => 'question:Q2'], $resolver('question-resolver'), 0.88),
            E::exposure('E19', $feb('20:17'), 'S8', 'feedback', ['T-LFILTER'], $chatAi, ['contrast', 'visual'], ['E18'], actor: E::CHATGPT),
            E::relates('C24', $feb('20:47'), 'event:E19', 'addresses', 'misconception:M1', E::interpreter('diagnose@1'), 0.9),
            E::claim('C25', $feb('20:47'), 'judges', ['event:E19'], ['answer' => [['question' => 'question:Q2', 'adequacy' => 'full']]], E::interpreter('judge-exposure@1'), 0.75),
        ];
    }

    /** T-LEFT correct, T-LFILTER as given, M1 present or absent. */
    private static function m1Verdict(string $filter, bool $present): array
    {
        return ['topics' => E::topics(['T-LEFT' => 'correct', 'T-LFILTER' => $filter]), 'misconceptions' => E::misconception('M1', $present)];
    }
}
