<?php

namespace Tests\Feature\Study;

use App\Brain\Store\JournalReader;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Modules and folders inside a workspace (docs/specs/workspaces.md, M2 step 2). */
class ModulesAndFoldersTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, RefreshesDatabase;

    private Modules $modules;

    private Folders $folders;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $biology;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modules = app(Modules::class);
        $this->folders = app(Folders::class);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->biology = app(Workspaces::class)->create($this->by, ['name' => 'Biology']);
    }

    public function test_modules_are_kept_in_order_and_recorded_in_the_journal(): void
    {
        $cells = $this->modules->create($this->by, $this->biology->id, ['title' => 'Cells', 'starts_on' => '2026-09-08', 'ends_on' => '2026-09-14']);
        $division = $this->modules->create($this->by, $this->biology->id, ['title' => 'Cell division']);
        $photo = $this->modules->create($this->by, $this->biology->id, ['title' => 'Photosynthesis']);

        $this->modules->move($this->by, $photo->id, 0);

        $this->assertSame(['Photosynthesis', 'Cells', 'Cell division'], $this->titles());
        $records = $this->moduleRecords();
        $this->assertSame(['module', $this->biology->id, 'Cells', 1, '2026-09-08'], [
            $records[0]->body['record_type'], $records[0]->body['workspace'], $records[0]->body['title'], $records[0]->body['position'], $records[0]->body['starts_on'],
        ]);
        // Moving renumbers every module whose position changed, each as a new revision.
        $this->assertSame([[$photo->id, 1], [$cells->id, 2], [$division->id, 3]], array_map(fn ($r) => [$r->body['record_id'], $r->body['position']], array_slice($records, -3)));
    }

    public function test_module_input_is_checked(): void
    {
        $this->assertThrows(fn () => $this->modules->create($this->by, $this->biology->id, ['title' => ' ']), Unprocessable::class);
        $this->assertThrows(fn () => $this->modules->create($this->by, $this->biology->id, ['title' => 'X', 'starts_on' => '2026-10-01', 'ends_on' => '2026-09-01']), Unprocessable::class);
        $this->assertSame([], $this->titles());
    }

    public function test_another_students_workspace_module_and_folder_are_missing(): void
    {
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private']);
        $module = $this->modules->create($bob, $theirs->id, ['title' => 'Theirs']);
        $folder = $this->folders->create($bob, 'module', $module->id, 'Secret');

        $this->assertThrows(fn () => $this->modules->list($this->by, $theirs->id), NotFound::class);
        $this->assertThrows(fn () => $this->modules->create($this->by, $theirs->id, ['title' => 'Mine']), NotFound::class);
        $this->assertThrows(fn () => $this->modules->update($this->by, $module->id, ['title' => 'Mine']), NotFound::class);
        $this->assertThrows(fn () => $this->folders->create($this->by, 'module', $module->id, 'Mine'), NotFound::class);
        $this->assertThrows(fn () => $this->folders->rename($this->by, $folder->id, 'Mine'), NotFound::class);

        $mine = $this->modules->create($this->by, $this->biology->id, ['title' => 'Mine']);
        $this->assertThrows(fn () => $this->folders->move($this->by, $folder->id, 'module', $mine->id), NotFound::class);
        $myFolder = $this->folders->create($this->by, 'module', $mine->id, 'Mine');
        $this->assertThrows(fn () => $this->folders->move($this->by, $myFolder->id, 'module', $module->id), NotFound::class);
    }

    public function test_folders_nest_in_order_and_come_back_as_a_tree(): void
    {
        $cells = $this->modules->create($this->by, $this->biology->id, ['title' => 'Cells']);
        $division = $this->modules->create($this->by, $this->biology->id, ['title' => 'Cell division']);
        $labs = $this->folders->create($this->by, 'module', $cells->id, 'Labs');
        $this->folders->create($this->by, 'folder', $labs->id, 'Lab 1');
        $this->folders->create($this->by, 'module', $cells->id, 'Reading');
        $quiz = $this->folders->create($this->by, 'module', $division->id, 'Practice quiz');

        $this->folders->reorder($this->by, $quiz->id, 0);
        $this->folders->rename($this->by, $labs->id, '  Lab   work ');

        $this->assertSame([['Lab work', 1], ['Lab 1', 2], ['Reading', 1], ['Practice quiz', 1]], $this->tree());
    }

    public function test_moving_a_folder_takes_everything_inside_and_respects_the_limits(): void
    {
        $cells = $this->modules->create($this->by, $this->biology->id, ['title' => 'Cells']);
        $division = $this->modules->create($this->by, $this->biology->id, ['title' => 'Cell division']);
        $labs = $this->folders->create($this->by, 'module', $cells->id, 'Labs');
        $lab1 = $this->folders->create($this->by, 'folder', $labs->id, 'Lab 1');

        $this->folders->move($this->by, $labs->id, 'module', $division->id);

        $lab1 = $this->folders->find($this->by, $lab1->id);
        $this->assertSame([$division->id, 2], [$lab1->moduleId, $lab1->depth]);
        $this->assertThrows(fn () => $this->folders->move($this->by, $labs->id, 'folder', $lab1->id), Conflict::class);

        // Build a chain to the deepest level; nothing may go below it.
        $parent = $lab1;
        for ($level = 3; $level <= Folders::MAX_DEPTH; $level++) {
            $parent = $this->folders->create($this->by, 'folder', $parent->id, "Level {$level}");
        }
        $this->assertThrows(fn () => $this->folders->create($this->by, 'folder', $parent->id, 'Too deep'), Conflict::class);
        $other = $this->folders->create($this->by, 'module', $cells->id, 'Other');
        $this->folders->create($this->by, 'folder', $other->id, 'Child');
        $this->assertThrows(fn () => $this->folders->move($this->by, $other->id, 'folder', $parent->id), Conflict::class);
    }

    public function test_only_empty_modules_and_folders_can_be_deleted(): void
    {
        $cells = $this->modules->create($this->by, $this->biology->id, ['title' => 'Cells']);
        $labs = $this->folders->create($this->by, 'module', $cells->id, 'Labs');
        $lab1 = $this->folders->create($this->by, 'folder', $labs->id, 'Lab 1');

        $this->assertThrows(fn () => $this->modules->delete($this->by, $cells->id), Conflict::class);
        $this->assertThrows(fn () => $this->folders->delete($this->by, $labs->id), Conflict::class);

        $this->folders->delete($this->by, $lab1->id);
        $this->folders->delete($this->by, $labs->id);
        $this->modules->delete($this->by, $cells->id);

        $this->assertSame([], $this->titles());
        $this->assertSame('deleted', last($this->moduleRecords())->body['status']);
    }

    /** @return list<string> */
    private function titles(): array
    {
        return array_map(fn ($m) => $m->title, $this->modules->list($this->by, $this->biology->id));
    }

    /** @return list<array{0: string, 1: int}> */
    private function tree(): array
    {
        return array_map(fn ($f) => [$f->name, $f->depth], $this->folders->tree($this->by, $this->biology->id));
    }

    private function moduleRecords(): array
    {
        return array_values(array_filter(
            app(JournalReader::class)->entries($this->learnerScopeOf($this->ada)),
            fn ($entry) => ($entry->body['record_type'] ?? null) === 'module',
        ));
    }
}
