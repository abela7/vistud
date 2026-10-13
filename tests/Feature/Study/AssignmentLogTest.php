<?php

namespace Tests\Feature\Study;

use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\AssignmentLog;
use App\Study\Workspaces;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** An assignment's activity log and stage progression. */
class AssignmentLogTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspaceId;

    private ActivityDetails $assignment;

    private AssignmentLog $log;

    private Activities $activities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->activities = app(Activities::class);
        $this->log = app(AssignmentLog::class);
        $this->workspaceId = app(Workspaces::class)->create($this->by, ['name' => 'Algorithms'])->id;
        $this->assignment = $this->activities->create($this->by, $this->workspaceId, [
            'kind' => 'assignment',
            'title' => 'Graph project',
        ]);
    }

    public function test_can_add_list_and_delete_log_entries(): void
    {
        $entry1 = $this->log->add($this->by, $this->assignment->id, 'understand', 'decision', 'Decided to use Dijkstra.', 'student');
        $entry2 = $this->log->add($this->by, $this->assignment->id, 'plan', 'advice', 'Break implementation into 3 milestones.', 'tutor');

        $this->assertSame('decision', $entry1->kind);
        $this->assertSame('understand', $entry1->stage);
        $this->assertSame('student', $entry1->by);
        $this->assertSame('Decided to use Dijkstra.', $entry1->text);

        $list = $this->log->list($this->by, $this->assignment->id);
        $this->assertCount(2, $list);
        $this->assertSame($entry2->id, $list[0]->id); // newest first
        $this->assertSame($entry1->id, $list[1]->id);

        $this->log->delete($this->by, $entry1->id);
        $listAfter = $this->log->list($this->by, $this->assignment->id);
        $this->assertCount(1, $listAfter);
        $this->assertSame($entry2->id, $listAfter[0]->id);
    }

    public function test_log_validation_and_limits(): void
    {
        // Empty text
        try {
            $this->log->add($this->by, $this->assignment->id, 'understand', 'decision', '   ');
            $this->fail('Expected refusal for empty text');
        } catch (Unprocessable $e) {
            $this->assertSame('Write what happened or what was decided.', $e->details['fields']['text'][0]);
        }

        // Unknown stage
        try {
            $this->log->add($this->by, $this->assignment->id, 'invalid_stage', 'decision', 'A note');
            $this->fail('Expected refusal for invalid stage');
        } catch (Unprocessable $e) {
            $this->assertSame('Unknown stage.', $e->details['fields']['stage'][0]);
        }

        // Unknown kind
        try {
            $this->log->add($this->by, $this->assignment->id, 'understand', 'invalid_kind', 'A note');
            $this->fail('Expected refusal for invalid kind');
        } catch (Unprocessable $e) {
            $this->assertSame('Choose what kind of entry this is.', $e->details['fields']['kind'][0]);
        }

        // Text exceeding 500 characters
        try {
            $this->log->add($this->by, $this->assignment->id, 'understand', 'decision', str_repeat('a', 501));
            $this->fail('Expected refusal for text > 500 chars');
        } catch (Unprocessable $e) {
            $this->assertStringContainsString('Keep an entry to 500 characters', $e->details['fields']['text'][0]);
        }
    }

    public function test_another_student_cannot_access_or_add_log(): void
    {
        $bob = $this->student();
        $bobPrincipal = $this->principal($bob);

        $this->expectException(NotFound::class);
        $this->log->add($bobPrincipal, $this->assignment->id, 'understand', 'decision', 'Sneaky entry');
    }

    public function test_log_deleted_with_assignment_and_course(): void
    {
        $this->log->add($this->by, $this->assignment->id, 'understand', 'decision', 'Point 1');
        $this->log->add($this->by, $this->assignment->id, 'plan', 'progress', 'Point 2');
        $this->assertCount(2, $this->log->list($this->by, $this->assignment->id));

        // Delete assignment
        $this->activities->delete($this->by, $this->assignment->id);
        $this->expectException(NotFound::class);
        $this->log->list($this->by, $this->assignment->id);
    }

    public function test_set_stage_validation_and_update(): void
    {
        $this->assertSame('understand', $this->assignment->stage);

        $this->activities->setStage($this->by, $this->assignment->id, 'plan');
        $updated = $this->activities->find($this->by, $this->assignment->id);
        $this->assertSame('plan', $updated->stage);

        $this->activities->setStage($this->by, $this->assignment->id, 'review');
        $updated = $this->activities->find($this->by, $this->assignment->id);
        $this->assertSame('review', $updated->stage);

        try {
            $this->activities->setStage($this->by, $this->assignment->id, 'invalid');
            $this->fail('Expected refusal for invalid stage');
        } catch (Unprocessable $e) {
            $this->assertSame('Unknown stage.', $e->details['fields']['stage'][0]);
        }
    }
}
