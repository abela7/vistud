<?php

namespace Tests\Feature\Study;

use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Plans;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A section keeps its own things in a folder inside the assignment's folder (the owner's review, 2026-10-04). */
class PlanSectionFoldersTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $os;

    private ActivityDetails $coursework;

    private Plans $plans;

    private Folders $folders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->os = app(Workspaces::class)->create($this->by, ['name' => 'Operating systems']);
        $module = app(Modules::class)->create($this->by, $this->os->id, ['title' => 'Week 3']);
        $this->coursework = app(Activities::class)->create($this->by, $this->os->id, ['title' => 'Scheduler coursework', 'module_id' => $module->id]);
        $this->plans = app(Plans::class);
        $this->folders = app(Folders::class);
    }

    public function test_a_section_gets_its_folder_inside_the_assignments_folder_when_first_needed_and_keeps_it(): void
    {
        $report = $this->plans->addPart($this->by, $this->coursework->id, 'Report', 60);
        $this->assertNull($this->plans->get($this->by, $this->coursework->id)->item($report->id)->folderId);

        $folder = $this->plans->folder($this->by, $report->id);
        $this->assertSame(['Report', $this->coursework->folderId, 2], [$folder->name, $folder->parentId, $folder->depth]);
        $this->assertSame($folder->id, $this->plans->get($this->by, $this->coursework->id)->item($report->id)->folderId);
        $this->assertSame($folder->id, $this->plans->folder($this->by, $report->id)->id);

        // Deleted by hand, it is made again when needed.
        $this->folders->delete($this->by, $folder->id);
        $again = $this->plans->folder($this->by, $report->id);
        $this->assertNotSame($folder->id, $again->id);
        $this->assertSame('Report', $again->name);
    }

    public function test_the_folder_is_renamed_with_its_section_and_only_a_section_has_one(): void
    {
        $report = $this->plans->addPart($this->by, $this->coursework->id, 'Report');
        $folder = $this->plans->folder($this->by, $report->id);
        $this->plans->update($this->by, $report->id, ['title' => 'Final report', 'marks' => 70]);
        $this->assertSame('Final report', $this->folders->find($this->by, $folder->id)->name);
        // A change that isn't the name leaves it alone.
        $this->folders->rename($this->by, $folder->id, 'My report files');
        $this->plans->update($this->by, $report->id, ['marks' => 60]);
        $this->assertSame('My report files', $this->folders->find($this->by, $folder->id)->name);

        $step = $this->plans->addStep($this->by, $this->coursework->id, 'Write it', $report->id);
        $this->assertThrows(fn () => $this->plans->folder($this->by, $step->id), Conflict::class);
    }

    public function test_deleting_a_section_takes_its_empty_folder_and_keeps_one_with_things_in_it(): void
    {
        $empty = $this->plans->addPart($this->by, $this->coursework->id, 'Research');
        $full = $this->plans->addPart($this->by, $this->coursework->id, 'Code');
        $emptyFolder = $this->plans->folder($this->by, $empty->id);
        $fullFolder = $this->plans->folder($this->by, $full->id);
        $this->folders->create($this->by, 'folder', $fullFolder->id, 'Screenshots');

        $this->assertNull($this->plans->delete($this->by, $empty->id));
        $this->assertThrows(fn () => $this->folders->find($this->by, $emptyFolder->id), NotFound::class);
        $this->assertSame('Code', $this->plans->delete($this->by, $full->id));
        $this->assertSame('Code', $this->folders->find($this->by, $fullFolder->id)->name);
    }

    public function test_clearing_the_plan_does_the_same_for_every_section(): void
    {
        $a = $this->plans->addPart($this->by, $this->coursework->id, 'A');
        $b = $this->plans->addPart($this->by, $this->coursework->id, 'B');
        $aFolder = $this->plans->folder($this->by, $a->id);
        $bFolder = $this->plans->folder($this->by, $b->id);
        $this->folders->create($this->by, 'folder', $bFolder->id, 'Drafts');

        $this->assertSame(2, $this->plans->clear($this->by, $this->coursework->id));
        $this->assertThrows(fn () => $this->folders->find($this->by, $aFolder->id), NotFound::class);
        $this->assertSame('B', $this->folders->find($this->by, $bFolder->id)->name);
    }

    public function test_another_student_cannot_reach_a_sections_folder(): void
    {
        $report = $this->plans->addPart($this->by, $this->coursework->id, 'Report');
        $this->assertThrows(fn () => $this->plans->folder($this->principal($this->student()), $report->id), NotFound::class);
    }
}
