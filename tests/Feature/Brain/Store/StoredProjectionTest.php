<?php

namespace Tests\Feature\Brain\Store;

use App\Brain\Journal\EntryFactory;
use App\Brain\Projection\ProjectionOptions;
use App\Brain\Projection\ProjectionRunner;
use App\Brain\Projection\Projector;
use App\Brain\Writer\JournalWriter;
use DateTimeImmutable;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Stored entries project exactly as the same specifications do in memory. */
class StoredProjectionTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    public function test_stored_and_in_memory_projections_are_identical(): void
    {
        $scope = $this->learnerScopeOf($this->student());
        $specs = [
            ...$this->setupEntries(),
            $this->taskRecord('R2', 'TK-2', 'LAB/q2'),
            $this->exercises('K3', 'TK-2', 'T-LEFT'),
            $this->attempt($scope, 'E1', 'TK-1', 'incorrect', '2026-10-15T14:12:00+01:00'),
            $this->attempt($scope, 'E2', 'TK-2', 'correct', '2026-10-15T15:41:00+01:00'),
            $this->attempt($scope, 'E3', 'TK-1', 'correct', '2026-10-22T19:05:00+01:00', ['session' => 'S-later']),
            [
                'id' => 'SR1', 'kind' => 'self_report', 'actor' => $this->learnerActor($scope), 'origin' => 'first_hand',
                'occurred_at' => '2026-10-22T19:30:00+01:00', 'links' => [['rel' => 'about', 'target' => 'topic:T-LEFT']],
                'body' => ['stance' => 'confident'], 'content' => ['text' => 'I get it now'],
            ],
        ];
        app(JournalWriter::class)->appendBatch($scope, $specs);

        $options = new ProjectionOptions(new DateTimeImmutable('2026-11-01T12:00:00Z'));
        $inMemory = (new Projector)->project(
            array_map(fn (array $spec, int $i) => EntryFactory::make($spec + ['tz' => 'UTC'], $scope->learnerId, $i + 1), $specs, array_keys($specs)),
            $options,
        );
        $stored = app(ProjectionRunner::class)->project($scope, $options);

        $this->assertSame($inMemory, $stored);
        $this->assertSame('working', $stored['topics']['T-LEFT']['label']);

        $belief = app(ProjectionRunner::class)->project($scope, new ProjectionOptions(new DateTimeImmutable('2026-11-01T12:00:00Z'), maxPosition: 6));
        $this->assertSame(6, $belief['position']);
    }

    public function test_the_snapshot_cache_holds_no_content(): void
    {
        $scope = $this->learnerScopeOf($this->student());
        app(JournalWriter::class)->appendBatch($scope, [...$this->setupEntries(), $this->attempt($scope, 'E1', 'TK-1', 'correct', '2026-10-15T14:12:00+01:00')]);
        $runner = app(ProjectionRunner::class);

        $this->assertNull($runner->latest($scope));
        $snapshot = $runner->refresh($scope, new ProjectionOptions(new DateTimeImmutable('2026-10-16T00:00:00Z')));

        // MySQL JSON columns reorder object keys; the values are what matter.
        $this->assertEquals($snapshot, $runner->latest($scope));
        $this->assertStringNotContainsString('LEFT JOIN', json_encode($snapshot));
    }
}
