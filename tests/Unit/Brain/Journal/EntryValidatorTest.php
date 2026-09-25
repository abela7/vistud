<?php

namespace Tests\Unit\Brain\Journal;

use App\Brain\Journal\EntryValidator;
use App\Brain\Journal\InvalidEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR 0002 §3–5 contracts, as EntryValidator enforces them. */
class EntryValidatorTest extends TestCase
{
    private const ACTOR = ['type' => 'learner', 'id' => 'L1', 'channel' => 'web'];

    public function test_a_minimal_question_is_valid(): void
    {
        EntryValidator::validate($this->question());
        $this->addToAssertionCount(1);
    }

    public function test_course_and_module_records_are_accepted(): void
    {
        EntryValidator::validate($this->record(['record_type' => 'course', 'record_id' => 'CS101', 'title' => 'Databases']));
        EntryValidator::validate($this->record(['record_type' => 'module', 'record_id' => 'CS101-M1', 'course' => 'CS101', 'title' => 'Joins', 'position' => 1]));
        $this->addToAssertionCount(2);
    }

    /** @return iterable<string, array{0: array, 1: string}> */
    public static function invalidEntries(): iterable
    {
        $question = fn (array $changes) => array_replace_recursive([
            'id' => 'Q1', 'kind' => 'question', 'actor' => self::ACTOR, 'origin' => 'first_hand',
            'occurred_at' => '2026-10-13T19:41:00+01:00', 'content' => ['text' => 'Why NULLs?'],
        ], $changes);

        yield 'unknown top-level field' => [$question(['secret' => 'x']), 'secret'];
        yield 'id with a colon' => [$question(['id' => 'a:b']), 'id'];
        yield 'time without an offset' => [$question(['occurred_at' => '2026-10-13 19:41']), 'occurred_at'];
        yield 'unknown time zone' => [$question(['tz' => 'Mars/Olympus']), 'tz'];
        yield 'observation without origin' => [array_diff_key($question([]), ['origin' => true]), 'origin'];
        yield 'bad link relation' => [$question(['links' => [['rel' => 'likes', 'target' => 'topic:T1']]]), 'links.0.rel'];
        yield 'unknown reference type' => [$question(['links' => [['rel' => 'about', 'target' => 'planet:T1']]]), 'links.0.target'];
        yield 'mention outside its field' => [$question(['mentions' => [['n' => 1, 'field' => 'text', 'start' => 0, 'end' => 99]]]), 'mentions.0'];
        yield 'mention of a missing field' => [$question(['mentions' => [['n' => 1, 'field' => 'other', 'start' => 0, 'end' => 1]]]), 'mentions.0.field'];
        yield 'content as a list' => [$question(['content' => ['just text']]), 'content'];
    }

    #[DataProvider('invalidEntries')]
    public function test_contract_violations_name_the_field(array $spec, string $field): void
    {
        try {
            EntryValidator::validate($spec);
            $this->fail('The entry was accepted.');
        } catch (InvalidEntry $e) {
            $this->assertSame($field, $e->field, $e->getMessage());
        }
    }

    public function test_a_module_needs_its_course(): void
    {
        $this->expectException(InvalidEntry::class);
        EntryValidator::validate($this->record(['record_type' => 'module', 'record_id' => 'M1', 'title' => 'Joins']));
    }

    public function test_emphasizes_must_cite_a_location_in_a_source(): void
    {
        $claim = [
            'id' => 'C1', 'kind' => 'claim', 'actor' => ['type' => 'processor', 'id' => 'interp', 'channel' => 'job'],
            'occurred_at' => '2026-10-13T12:00:00+01:00',
            'body' => [
                'type' => 'relates', 'targets' => ['activity:A-L7', 'topic:T-LEFT'],
                'value' => ['relation' => 'emphasizes', 'qualifiers' => ['emphasis' => 'exam_relevant'], 'status' => 'active'],
                'derived_from' => ['source:SRC-7'], 'confidence' => 0.9,
                'method' => ['kind' => 'interpreter', 'id' => 'local-interpreter', 'version' => '1'], 'review' => ['state' => 'pending'],
            ],
        ];

        $this->assertThrows(fn () => EntryValidator::validate($claim), 'body.derived_from');

        $claim['body']['derived_from'] = ['source:SRC-7#00:34:10'];
        EntryValidator::validate($claim);
    }

    public function test_references_lists_every_reference_with_its_role(): void
    {
        $refs = EntryValidator::references([
            'kind' => 'claim',
            'links' => [['rel' => 'about', 'target' => 'topic:T1']],
            'body' => [
                'targets' => ['event:E1'], 'derived_from' => ['event:E2'], 'supersedes' => ['claim:C0'],
                'value' => ['topics' => [['topic' => 'topic:T2', 'outcome' => 'correct']], 'demonstrates' => ['question:Q1']],
            ],
        ]);

        $this->assertSame([
            ['link:about', 'topic:T1'], ['target', 'event:E1'], ['derived_from', 'event:E2'], ['supersedes', 'claim:C0'],
            ['value', 'topic:T2'], ['value', 'question:Q1'],
        ], $refs);
    }

    private function assertThrows(callable $validate, string $field): void
    {
        try {
            $validate();
            $this->fail('The entry was accepted.');
        } catch (InvalidEntry $e) {
            $this->assertSame($field, $e->field);
        }
    }

    private function question(): array
    {
        return [
            'id' => 'Q1', 'kind' => 'question', 'actor' => self::ACTOR, 'origin' => 'first_hand',
            'occurred_at' => '2026-10-13T19:41:00+01:00', 'links' => [['rel' => 'about', 'target' => 'topic:T-LEFT']],
            'content' => ['text' => 'Why does LEFT JOIN give me rows full of NULLs?'],
        ];
    }

    private function record(array $body): array
    {
        return ['id' => 'R-'.$body['record_id'], 'kind' => 'record', 'actor' => self::ACTOR, 'occurred_at' => '2026-10-12T20:00:00+01:00', 'body' => $body];
    }
}
