<?php

namespace Tests\Feature\Brain\Store;

use App\Brain\Writer\JournalWriter;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use Illuminate\Database\QueryException;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * The journal is append-only at the database-permission level, as the audit log is (ADR 0002; config
 * vistud.database): the application's user adds entries and reads them, and can't change or remove one. An entry's
 * text may be deleted, as a redaction does (ADR 0002 §10), but never rewritten.
 */
class JournalPermissionsTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private LearnerScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scope = $this->learnerScopeOf($this->student());
        app(JournalWriter::class)->appendBatch($this->scope, [...$this->setupEntries(), $this->attempt($this->scope, 'E1', 'TK-1', 'incorrect', '2026-10-15T14:12:00+01:00')]);
    }

    public function test_entries_and_their_references_cannot_be_changed_or_removed(): void
    {
        $this->assertDenied(fn () => LearnerTables::query($this->scope, 'journal_entries')->where('id', 'E1')->update(['kind' => 'claim']));
        $this->assertDenied(fn () => LearnerTables::query($this->scope, 'journal_entries')->where('id', 'E1')->delete());
        $this->assertDenied(fn () => LearnerTables::query($this->scope, 'journal_refs')->where('entry_id', 'E1')->update(['ref_id' => 'other']));
        $this->assertDenied(fn () => LearnerTables::query($this->scope, 'journal_refs')->where('entry_id', 'E1')->delete());
        $this->assertDenied(fn () => LearnerTables::query($this->scope, 'journal_blocks')->delete());

        $this->assertSame('attempt', LearnerTables::query($this->scope, 'journal_entries')->where('id', 'E1')->value('kind'));
    }

    public function test_an_entrys_text_can_be_deleted_but_never_rewritten(): void
    {
        $this->assertDenied(fn () => LearnerTables::query($this->scope, 'journal_content')->where('entry_id', 'E1')->update(['text' => 'Something else.']));
        $this->assertDenied(fn () => LearnerTables::query($this->scope, 'journal_mentions')->where('entry_id', 'E1')->update(['start' => 0]));
        $this->assertStringContainsString('LEFT JOIN', LearnerTables::query($this->scope, 'journal_content')->where('entry_id', 'E1')->value('text'));

        $this->assertSame(1, LearnerTables::query($this->scope, 'journal_content')->where('entry_id', 'E1')->delete());
    }

    private function assertDenied(callable $statement): void
    {
        try {
            $statement();
        } catch (QueryException $e) {
            // 1142: command denied to user for table.
            $this->assertSame(1142, $e->errorInfo[1] ?? null, $e->getMessage());

            return;
        }
        $this->fail('The runtime database user was allowed to run the statement.');
    }
}
