<?php

namespace Tests\Feature\Api;

use App\Brain\Writer\JournalWriter;
use App\Platform\Database\LearnerTables;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\Support\OpenApi;
use Tests\TestCase;

/** GET /api/v1/journal/entries/{id}: a learner reads only their own entries (ADR 0003 D6). */
class JournalEntryApiTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    public function test_a_learner_reads_their_own_entry_with_its_content(): void
    {
        $student = $this->student();
        $scope = $this->learnerScopeOf($student);
        app(JournalWriter::class)->appendBatch($scope, [...$this->setupEntries(), $this->attempt($scope, 'E1', 'TK-1', 'incorrect', '2026-10-15T14:12:00+01:00')]);

        $response = $this->actingAs($student)->getJson('/api/v1/journal/entries/E1')
            ->assertOk()
            ->assertJsonPath('kind', 'attempt')
            ->assertJsonPath('position', 4)
            ->assertJsonPath('body.task', 'TK-1')
            ->assertJsonPath('blocked', false);

        $this->assertStringContainsString('LEFT JOIN', $response->json('content.answer'));
        $this->assertSame([], (new OpenApi)->validateResponse('GET', '/api/v1/journal/entries/{id}', 200, $response->json()));
    }

    public function test_another_learners_entry_gets_the_same_404_as_a_missing_one(): void
    {
        $owner = $this->student();
        $scope = $this->learnerScopeOf($owner);
        app(JournalWriter::class)->appendBatch($scope, [...$this->setupEntries(), $this->attempt($scope, 'E1', 'TK-1', 'incorrect', '2026-10-15T14:12:00+01:00')]);
        $intruder = $this->student();

        $theirs = $this->actingAs($intruder)->getJson('/api/v1/journal/entries/E1')->assertNotFound();
        $missing = $this->actingAs($intruder)->getJson('/api/v1/journal/entries/NO-SUCH-ENTRY')->assertNotFound();

        $strip = fn (array $json) => array_diff_key($json['error'], ['request_id' => true]);
        $this->assertSame($strip($missing->json()), $strip($theirs->json()));
        $this->assertStringNotContainsString('LEFT JOIN', $theirs->getContent());
    }

    public function test_an_admin_without_a_learner_stream_cannot_read_entries(): void
    {
        $owner = $this->student();
        $scope = $this->learnerScopeOf($owner);
        app(JournalWriter::class)->appendBatch($scope, $this->setupEntries());

        $this->actingAs($this->admin(student: false))->getJson('/api/v1/journal/entries/K1')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'student_role_required');
    }

    public function test_a_blocked_entry_is_served_without_content(): void
    {
        $student = $this->student();
        $scope = $this->learnerScopeOf($student);
        app(JournalWriter::class)->appendBatch($scope, [...$this->setupEntries(), $this->attempt($scope, 'E1', 'TK-1', 'incorrect', '2026-10-15T14:12:00+01:00')]);
        LearnerTables::insert($scope, 'journal_blocks', ['entity_type' => 'event', 'entity_id' => 'E1', 'redaction_id' => 'r-1', 'created_at' => now()]);

        $response = $this->actingAs($student)->getJson('/api/v1/journal/entries/E1')->assertOk()->assertJsonPath('blocked', true);

        $this->assertSame([], $response->json('content'));
        $this->assertStringNotContainsString('LEFT JOIN', $response->getContent());
    }

    public function test_guests_get_401(): void
    {
        $this->getJson('/api/v1/journal/entries/E1')->assertUnauthorized();
    }
}
