<?php

namespace Tests\Feature\Study;

use App\Brain\Journal\JournalEntry;
use App\Brain\Store\JournalReader;
use App\Models\User;
use App\Platform\Errors\Forbidden;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Workspaces;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A student's workspaces (docs/specs/workspaces.md, M2 step 1). */
class WorkspacesTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private Workspaces $workspaces;

    private User $ada;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaces = app(Workspaces::class);
        $this->ada = $this->student();
    }

    public function test_creating_stores_the_workspace_in_order_and_records_it_in_the_journal(): void
    {
        $by = $this->principal($this->ada);

        $biology = $this->workspaces->create($by, ['name' => '  Biology  ', 'code' => 'BIO101', 'term' => 'Autumn 2026', 'colour' => 'green', 'icon' => 'microscope']);
        $maths = $this->workspaces->create($by, ['name' => 'Mathematics']);

        $this->assertSame(['Biology', 'BIO101 · Autumn 2026', 'green', 'microscope', 1], [$biology->name, $biology->subtitle(), $biology->colour, $biology->icon, $biology->position]);
        $this->assertSame(['blue', 'book-open', 2], [$maths->colour, $maths->icon, $maths->position]);
        $this->assertSame(['Biology', 'Mathematics'], array_map(fn ($w) => $w->name, $this->workspaces->list($by)));

        $records = $this->workspaceRecords($this->ada);
        $this->assertCount(2, $records);
        $this->assertSame(['workspace', $biology->id, 1, 'Biology', 'BIO101', 'active'], [
            $records[0]->body['record_type'], $records[0]->body['record_id'], $records[0]->body['revision'],
            $records[0]->body['title'], $records[0]->body['code'], $records[0]->body['status'],
        ]);
    }

    public function test_bad_input_is_refused_field_by_field(): void
    {
        try {
            $this->workspaces->create($this->principal($this->ada), [
                'name' => ' ', 'code' => str_repeat('x', 21), 'starts_on' => '2026-10-01', 'ends_on' => '2026-09-01',
                'colour' => 'chartreuse', 'icon' => 'rocket',
            ]);
            $this->fail('The workspace was created.');
        } catch (Unprocessable $e) {
            $this->assertSame('validation_failed', $e->errorCode);
            $this->assertEqualsCanonicalizing(['name', 'code', 'ends_on', 'colour', 'icon'], array_keys($e->details['fields']));
        }

        $this->assertThrows(fn () => $this->workspaces->create($this->principal($this->ada), ['name' => str_repeat('a', 81)]), Unprocessable::class);
        $this->assertThrows(fn () => $this->workspaces->create($this->principal($this->ada), ['name' => 'X', 'starts_on' => '2026-02-30']), Unprocessable::class);
        $this->assertSame([], $this->workspaces->list($this->principal($this->ada)));
    }

    public function test_another_students_workspace_is_exactly_as_missing_as_one_that_does_not_exist(): void
    {
        $bob = $this->student();
        $theirs = $this->workspaces->create($this->principal($bob), ['name' => 'Private']);
        $by = $this->principal($this->ada);

        foreach ([$theirs->id, 'no-such-id'] as $id) {
            $this->assertThrows(fn () => $this->workspaces->find($by, $id), NotFound::class);
            $this->assertThrows(fn () => $this->workspaces->update($by, $id, ['name' => 'Mine now']), NotFound::class);
            $this->assertThrows(fn () => $this->workspaces->archive($by, $id), NotFound::class);
        }
        $this->assertSame([], $this->workspaces->list($by));
        $this->assertSame('Private', $this->workspaces->find($this->principal($bob), $theirs->id)->name);
    }

    public function test_an_account_without_a_journal_cannot_have_workspaces(): void
    {
        $adminOnly = $this->admin(student: false);

        $this->assertThrows(fn () => $this->workspaces->list($this->principal($adminOnly)), Forbidden::class);
        $this->assertThrows(fn () => $this->workspaces->create($this->principal($adminOnly), ['name' => 'X']), Forbidden::class);
    }

    public function test_updating_bumps_the_revision_in_the_row_and_the_journal(): void
    {
        $by = $this->principal($this->ada);
        $workspace = $this->workspaces->create($by, ['name' => 'Biology']);

        $updated = $this->workspaces->update($by, $workspace->id, ['name' => 'Human biology', 'colour' => 'teal', 'icon' => 'brain']);

        $this->assertSame(['Human biology', 'teal', 'brain'], [$updated->name, $updated->colour, $updated->icon]);
        $this->assertSame([1, 2], array_map(fn ($r) => $r->body['revision'], $this->workspaceRecords($this->ada)));
    }

    public function test_archiving_hides_the_workspace_and_restoring_brings_it_back_at_the_end(): void
    {
        $by = $this->principal($this->ada);
        $biology = $this->workspaces->create($by, ['name' => 'Biology']);
        $this->workspaces->create($by, ['name' => 'Mathematics']);

        $this->workspaces->archive($by, $biology->id);
        $this->workspaces->archive($by, $biology->id);

        $this->assertSame(['Mathematics'], array_map(fn ($w) => $w->name, $this->workspaces->list($by)));
        $this->assertSame(['Biology'], array_map(fn ($w) => $w->name, $this->workspaces->list($by, archived: true)));
        $this->assertTrue($this->workspaces->find($by, $biology->id)->archived());

        $this->workspaces->restore($by, $biology->id);

        $this->assertSame(['Mathematics', 'Biology'], array_map(fn ($w) => $w->name, $this->workspaces->list($by)));
        $this->assertSame(['active', 'archived', 'active'], array_map(fn ($r) => $r->body['status'], array_slice($this->workspaceRecords($this->ada), -3)));
    }

    /** @return list<JournalEntry> */
    private function workspaceRecords(User $student): array
    {
        return array_values(array_filter(
            app(JournalReader::class)->entries($this->learnerScopeOf($student)),
            fn ($entry) => ($entry->body['record_type'] ?? null) === 'workspace',
        ));
    }
}
