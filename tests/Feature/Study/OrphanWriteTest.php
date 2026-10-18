<?php

namespace Tests\Feature\Study;

use App\Engine\Toolbox;
use App\Engine\Tools\Context;
use App\Platform\Access\Guard;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Deleting a course removes its topics, and every write resolves through the active course so a stale topic id from a
 * deleted course writes nothing and answers as not found.
 */
class OrphanWriteTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_deleting_a_course_leaves_its_topic_ids_not_found_and_writes_nothing(): void
    {
        $by = $this->principal($this->student());
        $workspaces = app(Workspaces::class);
        $topics = app(Topics::class);
        $toolbox = app(Toolbox::class);

        $deleted = $workspaces->create($by, ['name' => 'Old Databases']);
        $active = $workspaces->create($by, ['name' => 'Operating Systems']);
        $staleTopic = $topics->create($by, $deleted->id, 'Relational algebra');

        $workspaces->delete($by, $deleted->id);
        $entriesBefore = DB::table('journal_entries')->count();

        // Calling in the deleted course's context answers not found and writes nothing.
        $inDeleted = new Context($deleted->id, null, null, 'UTC');
        $this->assertSame(
            'Not found: there is no such thing in this course.',
            $toolbox->run($by, $inDeleted, 'set_topic_status', ['topic' => $staleTopic->id, 'status' => 'understood', 'reason' => 'Stale id']),
        );
        $this->assertSame($entriesBefore, DB::table('journal_entries')->count());

        // Calling in another course's context with the stale id answers not found in that course and writes nothing.
        $inActive = new Context($active->id, null, null, 'UTC');
        $this->assertSame(
            "There is no topic called \"{$staleTopic->id}\" in this course.",
            $toolbox->run($by, $inActive, 'set_topic_status', ['topic' => $staleTopic->id, 'status' => 'understood', 'reason' => 'Stale id']),
        );
        $this->assertSame($entriesBefore, DB::table('journal_entries')->count());

        // Even if an orphan topic row survived in the table, Topics refuses to touch it.
        $scope = Guard::learner($by);
        LearnerTables::insert($scope, 'topics', [
            'id' => $staleTopic->id, 'workspace_id' => $deleted->id, 'module_id' => null, 'folder_id' => null,
            'name' => 'Relational algebra', 'position' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(NotFound::class);
        $topics->mark($by, $staleTopic->id, 'understood');
    }
}
