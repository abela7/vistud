<?php

namespace Tests\Unit\Brain\Projection\Support;

/**
 * docs/specs/golden-replay-sql-joins.md, positions 1–86, as a developer
 * fixture for WP4. Each call below is one position, in order. The
 * independent acceptance version belongs to work package V1.
 */
final class GoldenScenario
{
    public const SETUP = '2026-10-12 20:00';

    public static function build(): Scenario
    {
        $s = Scenario::make();
        $at = self::SETUP;

        // 1–4 activities
        $s->activity('A-L7', 'lecture', '2026-10-13 10:00', $at)
            ->activity('A-LAB4', 'lab', '2026-10-15 14:00', $at)
            ->activity('A-Q1711', 'quiz', '2026-11-17 09:00', $at)
            ->activity('A-P25', 'exam', '2025-06-01 09:00', $at);
        // 5–10 tasks
        foreach (['TK-Q3', 'TK-Q4', 'TK-Q5', 'TK-QZ2', 'TK-P6A', 'TK-P6B'] as $task) {
            $s->task($task, $at);
        }
        // 11–15 topics
        foreach (['T-JOIN', 'T-INNER', 'T-LEFT', 'T-NULL', 'T-LFILTER'] as $topic) {
            $s->topic($topic, $at);
        }
        // 16–20 relations
        $s->partOf('T-INNER', 'T-JOIN')->partOf('T-LEFT', 'T-JOIN')->partOf('T-LFILTER', 'T-LEFT')
            ->claim('K9', 'relates', ['topic:T-NULL', 'topic:T-LEFT'], ['relation' => 'prerequisite_of', 'status' => 'active'], method: 'person', at: $at)
            ->claim('K10', 'relates', ['topic:T-INNER', 'topic:T-LEFT'], ['relation' => 'contrasts_with', 'status' => 'active'], method: 'person', at: $at);
        // 21–23 covers
        foreach (['T-JOIN', 'T-INNER', 'T-LEFT'] as $i => $topic) {
            $s->claim('K'.(11 + $i), 'relates', ['activity:A-L7', "topic:{$topic}"], ['relation' => 'covers', 'status' => 'active'], method: 'person', at: $at);
        }
        // 24–34 exercises
        foreach (['TK-Q3', 'TK-Q4', 'TK-Q5', 'TK-QZ2'] as $task) {
            $s->exercises($task, 'T-LEFT', 'T-LFILTER');
        }
        $s->exercises('TK-P6A', 'T-LEFT')->exercises('TK-P6B', 'T-LEFT', 'T-LFILTER');

        // 35–47: Tue 13 Oct
        $s->exposure('E1', '2026-10-13 10:00', ['topic:T-JOIN', 'topic:T-INNER', 'topic:T-LEFT'], ['format' => 'lecture', 'until' => '2026-10-13 11:00', 'session' => 'S0', 'activity' => 'A-L7', 'origin' => 'reported'])
            ->record('SRC-7', 'source', '2026-10-13 11:20')
            ->record('X-7', 'extraction', '2026-10-13 11:50')
            ->claim('C1', 'relates', ['activity:A-L7', 'topic:T-LEFT'], ['relation' => 'emphasizes', 'qualifiers' => ['emphasis' => 'exam_relevant'], 'status' => 'active'],
                at: '2026-10-13 12:00', state: 'pending', derivedFrom: ['source:SRC-7#00:34:10'])
            ->learnerReview('C2', 'claim:C1', 'accept', '2026-10-13 12:30')
            ->selfReport('E2', '2026-10-13 19:40', 'confused', ['topic:T-LEFT', 'topic:T-INNER'], 'S1')
            ->ask('E3', '2026-10-13 19:41', ['topic:T-LEFT'], 'S1')
            ->question('C3', 'Q1', '2026-10-13 19:41')
            ->refersTo('C4', 'E3', 'Q1', '2026-10-13 19:41')
            ->stemsFrom('C5', 'Q1', 'topic:T-LEFT', '2026-10-13 19:41')
            ->exposure('E4', '2026-10-13 19:43', ['topic:T-LEFT'], ['by' => 'chat_ai', 'approach' => ['analogy', 'worked_example'], 'responds_to' => 'E3', 'session' => 'S1'])
            ->selfReport('E5', '2026-10-13 19:44', 'clicked', ['question:Q1'], 'S1', triggeredBy: 'E4')
            ->judges('C6', 'E4', ['answer' => [['question' => 'question:Q1', 'adequacy' => 'full']], 'effect' => [['target' => 'question:Q1', 'effect' => 'helped']]], '2026-10-13 20:14');

        // 48–64: Thu 15 Oct
        $lab = ['setting' => 'lab', 'session' => 'S2'];
        $s->attempt('E6', '2026-10-15 14:12', 'TK-Q3', 'incorrect', 'auto', $lab)
            ->attempt('E7', '2026-10-15 14:20', 'TK-Q4', 'incorrect', 'auto', $lab)
            ->misconception('C7', 'M1', ['T-LFILTER'], '2026-10-15 14:50')
            ->judges('C8', 'E6', self::blamesFilter(), '2026-10-15 14:50')
            ->judges('C9', 'E7', self::blamesFilter(), '2026-10-15 14:50')
            ->ask('E8', '2026-10-15 15:20', ['topic:T-LFILTER'], 'S3')
            ->question('C10', 'Q2', '2026-10-15 15:20')
            ->refersTo('C11', 'E8', 'Q2', '2026-10-15 15:20')
            ->stemsFrom('C12', 'Q2', 'misconception:M1', '2026-10-15 15:20')
            ->exposure('E9', '2026-10-15 15:22', ['topic:T-LFILTER'], ['format' => 'feedback', 'by' => 'chat_ai', 'approach' => ['contrast', 'visual'], 'responds_to' => 'E8', 'session' => 'S3'])
            ->attempt('E10', '2026-10-15 15:41', 'TK-Q5', 'correct', 'auto', ['setting' => 'lab', 'session' => 'S4'])
            ->attempt('E11', '2026-10-15 15:45', 'TK-Q3', 'correct', 'auto', ['setting' => 'lab', 'session' => 'S4'])
            ->addresses('C13', 'E9', 'M1', '2026-10-15 15:52')
            ->judges('C14', 'E9', ['answer' => [['question' => 'question:Q2', 'adequacy' => 'full']]], '2026-10-15 15:52')
            ->judges('C15', 'E10', self::bothCorrect(), '2026-10-15 16:15')
            ->judges('C16', 'E11', ['overall' => 'correct', 'misconceptions' => [['misconception' => 'misconception:M1', 'present' => false]]], '2026-10-15 16:15')
            ->judges('C17', 'E9', ['effect' => [['target' => 'misconception:M1', 'effect' => 'helped']]], '2026-10-15 16:15');

        // 65–74: Thu 22 Oct
        $chat = ['setting' => 'chat', 'session' => 'S5'];
        $s->task('TK-C1', '2026-10-22 19:04')->exercises('TK-C1', 'T-LEFT', 'T-LFILTER')
            ->attempt('E12', '2026-10-22 19:05', 'TK-C1', 'correct', 'ai', ['form' => 'explain'] + $chat)
            ->task('TK-C2', '2026-10-22 19:11')->exercises('TK-C2', 'T-LEFT', 'T-LFILTER')
            ->attempt('E13', '2026-10-22 19:12', 'TK-C2', 'correct', 'ai', $chat)
            ->judges('C18', 'E12', self::bothCorrect() + ['own_words' => true, 'demonstrates' => ['question:Q1', 'question:Q2']], '2026-10-22 19:42')
            ->judges('C19', 'E13', self::bothCorrect(), '2026-10-22 19:42');

        // 75–76: Tue 17 Nov
        $s->attempt('E14', '2026-11-17 18:05', 'TK-QZ2', 'correct', 'auto', ['setting' => 'quiz', 'session' => 'S6'])
            ->judges('C20', 'E14', ['overall' => 'correct', 'misconceptions' => [['misconception' => 'misconception:M1', 'present' => false]]], '2026-11-17 18:35');

        // 77–86: Wed 3 Feb 2027
        $practice = ['setting' => 'practice', 'session' => 'S7'];
        $s->attempt('E15', '2027-02-03 19:35', 'TK-P6A', 'correct', 'auto', $practice)
            ->attempt('E16', '2027-02-03 19:42', 'TK-P6B', 'incorrect', 'auto', $practice)
            ->selfReport('E17', '2027-02-03 19:43', 'forgot', ['topic:T-LFILTER'], 'S7')
            ->judges('C21', 'E15', ['topics' => [['topic' => 'topic:T-LEFT', 'outcome' => 'correct']]], '2027-02-03 20:13')
            ->judges('C22', 'E16', self::blamesFilter('forgot'), '2027-02-03 20:13', ['derived_from' => ['event:E16', 'event:E17']])
            ->ask('E18', '2027-02-03 20:15', [], 'S8')
            ->refersTo('C23', 'E18', 'Q2', '2027-02-03 20:15')
            ->exposure('E19', '2027-02-03 20:17', ['topic:T-LFILTER'], ['format' => 'feedback', 'by' => 'chat_ai', 'approach' => ['contrast', 'visual'], 'responds_to' => 'E18', 'session' => 'S8'])
            ->addresses('C24', 'E19', 'M1', '2027-02-03 20:47')
            ->judges('C25', 'E19', ['answer' => [['question' => 'question:Q2', 'adequacy' => 'full']]], '2027-02-03 20:47');

        return $s;
    }

    private static function blamesFilter(string $cause = 'misconception'): array
    {
        return [
            'topics' => [['topic' => 'topic:T-LEFT', 'outcome' => 'correct'], ['topic' => 'topic:T-LFILTER', 'outcome' => 'incorrect']],
            'misconceptions' => [['misconception' => 'misconception:M1', 'present' => true]],
            'cause' => $cause,
        ];
    }

    private static function bothCorrect(): array
    {
        return [
            'topics' => [['topic' => 'topic:T-LEFT', 'outcome' => 'correct'], ['topic' => 'topic:T-LFILTER', 'outcome' => 'correct']],
            'misconceptions' => [['misconception' => 'misconception:M1', 'present' => false]],
        ];
    }
}
