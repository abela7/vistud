<?php

namespace Tests\Feature\Brain\Writer;

use App\Brain\Writer\AppendResult;
use App\Brain\Writer\JournalWriter;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\AppError;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** ADR 0002 §3–6 and §9: what the journal writer accepts, refuses and deduplicates. */
class JournalWriterTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private JournalWriter $writer;

    private LearnerScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = app(JournalWriter::class);
        $this->scope = $this->learnerScopeOf($this->student());
    }

    public function test_positions_are_gap_free_and_received_at_is_the_servers_time(): void
    {
        $this->travelTo('2026-10-15 13:00:00');

        $results = $this->writer->appendBatch($this->scope, [
            ...$this->setupEntries(),
            $this->attempt($this->scope, 'E1', 'TK-1', 'incorrect', '2026-10-15T14:12:00+01:00', ['received_at' => '1999-01-01T00:00:00Z']),
        ]);

        $this->assertSame([1, 2, 3, 4], array_map(fn (AppendResult $r) => $r->position(), $results));
        $this->assertSame(4, (int) DB::table('learners')->where('id', $this->scope->learnerId)->value('journal_position'));
        $this->assertSame('2026-10-15 13:00:00', $results[3]->entry->receivedAt->format('Y-m-d H:i:s'));

        $next = $this->writer->append($this->scope, $this->attempt($this->scope, 'E2', 'TK-1', 'correct', '2026-10-16T10:00:00+01:00'));
        $this->assertSame(5, $next->position());
    }

    public function test_content_is_stored_apart_from_the_entry(): void
    {
        $this->writer->appendBatch($this->scope, [...$this->setupEntries(), $this->attempt($this->scope, 'E1', 'TK-1', 'incorrect', '2026-10-15T14:12:00+01:00')]);

        $row = LearnerTables::query($this->scope, 'journal_entries')->where('id', 'E1')->first();
        $this->assertStringNotContainsString('customers', $row->body.$row->links);
        $this->assertSame('["answer"]', $row->content_fields);
        $this->assertStringContainsString('LEFT JOIN', LearnerTables::query($this->scope, 'journal_content')->where('entry_id', 'E1')->value('text'));
    }

    public function test_mentions_must_point_inside_a_content_field(): void
    {
        $this->writer->appendBatch($this->scope, $this->setupEntries());
        $spec = $this->attempt($this->scope, 'E1', 'TK-1', 'incorrect', '2026-10-15T14:12:00+01:00', [
            'mentions' => [['n' => 1, 'field' => 'answer', 'start' => 30, 'end' => 500]],
        ]);

        $this->assertRefused(fn () => $this->writer->append($this->scope, $spec), 'invalid_entry', ['field' => 'mentions.0']);
    }

    public function test_the_same_id_with_the_same_content_is_a_duplicate_and_with_other_content_a_conflict(): void
    {
        $this->writer->appendBatch($this->scope, $this->setupEntries());
        $spec = $this->attempt($this->scope, 'E1', 'TK-1', 'incorrect', '2026-10-15T14:12:00+01:00');
        $first = $this->writer->append($this->scope, $spec);

        // The same instant written with another offset is the same content.
        $again = $this->writer->append($this->scope, array_replace($spec, ['occurred_at' => '2026-10-15T13:12:00Z']));
        $this->assertSame([AppendResult::DUPLICATE, $first->position()], [$again->status, $again->position()]);

        $this->assertRefused(fn () => $this->writer->append($this->scope, $this->attempt($this->scope, 'E1', 'TK-1', 'correct', '2026-10-15T14:12:00+01:00')), 'id_conflict');
        $this->assertSame(4, LearnerTables::query($this->scope, 'journal_entries')->count());
    }

    public function test_a_capture_key_makes_resending_safe(): void
    {
        $this->writer->appendBatch($this->scope, $this->setupEntries());
        $spec = $this->attempt($this->scope, 'E1', 'TK-1', 'correct', '2026-10-22T19:12:00+01:00', ['capture_key' => 'chat-42']);
        $first = $this->writer->append($this->scope, $spec);

        // A retry from a chat client arrives with a new server-made ID.
        $retry = $this->writer->append($this->scope, array_replace($spec, ['id' => 'E1-retry']));
        $this->assertSame([AppendResult::DUPLICATE, 'E1'], [$retry->status, $retry->entry->id]);

        $changed = $this->attempt($this->scope, 'E1-other', 'TK-1', 'incorrect', '2026-10-22T19:12:00+01:00', ['capture_key' => 'chat-42']);
        $this->assertRefused(fn () => $this->writer->append($this->scope, $changed), 'capture_key_conflict');
        $this->assertSame($first->position(), $retry->position());
    }

    public function test_contract_errors_name_the_field_but_never_repeat_the_value(): void
    {
        $spec = $this->attempt($this->scope, 'E1', 'TK-1', 'incorrect', '2026-10-15T14:12:00+01:00', ['body' => ['form' => 'my password is hunter2']]);

        try {
            $this->writer->append($this->scope, $spec);
            $this->fail('The entry was accepted.');
        } catch (Unprocessable $e) {
            $this->assertSame(['invalid_entry', ['field' => 'body.form']], [$e->errorCode, $e->details]);
            $this->assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    public function test_auto_judged_attempts_need_a_trusted_checker(): void
    {
        $this->writer->appendBatch($this->scope, $this->setupEntries());

        $noChecker = $this->attempt($this->scope, 'E1', 'TK-1', 'correct', '2026-10-15T14:12:00+01:00');
        unset($noChecker['body']['checker']);
        $this->assertRefused(fn () => $this->writer->append($this->scope, $noChecker), 'invalid_entry', ['field' => 'body.checker']);

        // The back of the learner's own flashcard is not a trusted key (ADR 0003 §9.4).
        $ownKey = $this->attempt($this->scope, 'E2', 'TK-1', 'correct', '2026-10-15T14:12:00+01:00', ['body' => ['checker' => ['key_source' => 'learner']]]);
        $this->assertRefused(fn () => $this->writer->append($this->scope, $ownKey), 'invalid_entry', ['field' => 'body.checker.key_source']);

        $self = $this->attempt($this->scope, 'E3', 'TK-1', 'correct', '2026-10-15T14:12:00+01:00', ['body' => ['judged_by' => 'self', 'checker' => ['key_source' => 'learner']]]);
        $this->assertSame(AppendResult::RECORDED, $this->writer->append($this->scope, $self)->status);
    }

    public function test_a_reference_to_another_learners_entry_looks_exactly_like_a_missing_one(): void
    {
        $other = $this->learnerScopeOf($this->student());
        $this->writer->appendBatch($other, [...$this->setupEntries(), $this->attempt($other, 'THEIRS', 'TK-1', 'correct', '2026-10-15T14:12:00+01:00')]);
        $this->writer->appendBatch($this->scope, $this->setupEntries());

        $toTheirs = $this->learnerReview($this->scope, 'D1', 'event:THEIRS', 'dispute', '2026-10-16T10:00:00+01:00');
        $toNothing = $this->learnerReview($this->scope, 'D2', 'event:NOTHING', 'dispute', '2026-10-16T10:00:00+01:00');

        $this->assertSame($this->refusal(fn () => $this->writer->append($this->scope, $toNothing)), $this->refusal(fn () => $this->writer->append($this->scope, $toTheirs)));
        $this->assertSame('unknown_reference', $this->refusal(fn () => $this->writer->append($this->scope, $toTheirs))['code']);
    }

    public function test_entities_must_be_introduced_before_they_are_referenced(): void
    {
        $this->assertRefused(fn () => $this->writer->append($this->scope, $this->exercises('K9', 'TK-1', 'T-LEFT')), 'unknown_reference');

        // Within one batch, earlier entries count.
        $results = $this->writer->appendBatch($this->scope, $this->setupEntries());
        $this->assertCount(3, $results);
    }

    public function test_a_learner_actor_can_only_write_to_their_own_journal(): void
    {
        $other = $this->learnerScopeOf($this->student());
        $spec = $this->attempt($other, 'E1', 'TK-1', 'correct', '2026-10-15T14:12:00+01:00');

        $this->assertRefused(fn () => $this->writer->append($this->scope, $spec), 'invalid_entry', ['field' => 'actor.id']);
    }

    public function test_the_learner_cannot_reject_a_verdict_on_their_own_work_but_can_dispute_it(): void
    {
        // ADR 0002 §6 and golden replay edge case D1.
        $this->writer->appendBatch($this->scope, [
            ...$this->setupEntries(),
            $this->attempt($this->scope, 'E1', 'TK-1', 'correct', '2026-10-15T14:12:00+01:00', ['body' => ['judged_by' => 'ai', 'checker' => null]]),
            $this->interpreterJudges('C1', 'E1', ['overall' => 'incorrect'], '2026-10-15T14:50:00+01:00'),
        ]);

        $this->assertRefused(
            fn () => $this->writer->append($this->scope, $this->learnerReview($this->scope, 'R1-reject', 'claim:C1', 'reject', '2026-10-15T15:00:00+01:00')),
            'review_not_allowed',
            ['use' => 'dispute'],
        );

        $dispute = $this->writer->append($this->scope, $this->learnerReview($this->scope, 'R1-dispute', 'claim:C1', 'dispute', '2026-10-15T15:00:00+01:00'));
        $this->assertSame(AppendResult::RECORDED, $dispute->status);
    }

    public function test_claims_and_other_entries_use_their_own_reference_prefix(): void
    {
        $this->writer->appendBatch($this->scope, [
            ...$this->setupEntries(),
            $this->attempt($this->scope, 'E1', 'TK-1', 'correct', '2026-10-15T14:12:00+01:00'),
        ]);

        $wrongPrefix = $this->interpreterJudges('C1', 'E1', ['overall' => 'correct'], '2026-10-15T14:50:00+01:00');
        $wrongPrefix['body']['targets'] = ['claim:E1'];

        $this->assertRefused(fn () => $this->writer->append($this->scope, $wrongPrefix), 'invalid_entry');
    }

    public function test_a_batch_is_all_or_nothing(): void
    {
        $this->assertRefused(fn () => $this->writer->appendBatch($this->scope, [
            ...$this->setupEntries(),
            $this->attempt($this->scope, 'E1', 'TK-UNKNOWN', 'correct', '2026-10-15T14:12:00+01:00'),
        ]), 'unknown_reference');

        $this->assertSame(0, LearnerTables::query($this->scope, 'journal_entries')->count());
        $this->assertSame(0, (int) DB::table('learners')->where('id', $this->scope->learnerId)->value('journal_position'));
    }

    public function test_an_incomplete_split_is_refused_when_it_would_take_effect(): void
    {
        // Contract change approved in the WP4 review: a split may only become
        // effective when complete. As a pending proposal it may be incomplete.
        $this->writer->appendBatch($this->scope, [
            ...$this->setupEntries(),
            $this->attempt($this->scope, 'E1', 'TK-1', 'correct', '2026-10-15T14:12:00+01:00'),
            $this->attempt($this->scope, 'E2', 'TK-1', 'incorrect', '2026-10-16T14:12:00+01:00'),
            $this->definesTopic('K-IN', 'T-INNER'),
            $this->definesTopic('K-OUT', 'T-OUTER'),
        ]);
        $split = fn (string $state, array $assignments) => [
            'id' => 'SPLIT-'.$state.'-'.count($assignments), 'kind' => 'claim', 'actor' => $this->learnerActor($this->scope), 'occurred_at' => '2026-10-17T10:00:00+01:00',
            'body' => [
                'type' => 'splits_into', 'targets' => ['topic:T-LEFT'],
                'value' => ['into' => ['topic:T-INNER', 'topic:T-OUTER'], 'assignments' => $assignments],
                'confidence' => null, 'method' => ['kind' => 'person', 'id' => $this->scope->learnerId, 'version' => '1'], 'review' => ['state' => $state],
            ],
        ];
        $partial = [['ref' => 'event:E1', 'to' => 'topic:T-INNER']];

        $this->assertRefused(fn () => $this->writer->append($this->scope, $split('accepted', $partial)), 'split_incomplete', [
            'problems' => ['unassigned_evidence'],
            'unassigned' => ['event:E2'],
        ]);

        // Pending, it's only a proposal; accepting it is what gets refused.
        $this->writer->append($this->scope, $split('pending', $partial));
        $this->assertRefused(
            fn () => $this->writer->append($this->scope, $this->learnerReview($this->scope, 'YES', 'claim:SPLIT-pending-1', 'accept', '2026-10-17T11:00:00+01:00')),
            'split_incomplete',
        );

        $complete = [...$partial, ['ref' => 'event:E2', 'to' => 'topic:T-OUTER']];
        $this->assertSame(AppendResult::RECORDED, $this->writer->append($this->scope, $split('accepted', $complete))->status);
    }

    public function test_a_learner_that_does_not_exist_is_not_found(): void
    {
        $this->assertThrows(fn () => $this->writer->append(LearnerScope::forJob('no-such-learner'), $this->definesTopic('K1', 'T-1')), NotFound::class);
    }

    private function assertRefused(callable $append, ?string $code, array $details = []): void
    {
        if ($code === null) {
            return;
        }
        $refusal = $this->refusal($append);
        $this->assertSame($code, $refusal['code'], $refusal['message']);
        foreach ($details as $key => $value) {
            $this->assertSame($value, $refusal['details'][$key] ?? null);
        }
    }

    /** @return array{code: string, message: string, details: array, class: string} */
    private function refusal(callable $append): array
    {
        try {
            $append();
        } catch (AppError $e) {
            return ['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details, 'class' => $e::class];
        }
        $this->fail('The entry was accepted.');
    }
}
