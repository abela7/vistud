<?php

namespace Tests\Feature\Study;

use App\Brain\Store\JournalReader;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\Files;
use App\Study\Folders;
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsJournalEntries;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Assignments with a deadline (a day and a time) and their own folder for files (the owner's review, 2026-10-02). */
class AssignmentsTest extends TestCase
{
    use BuildsJournalEntries, CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    private ModuleDetails $week1;

    private Activities $activities;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ada = $this->student();
        // Three hours ahead of UTC: a deadline is in the student's own time.
        DB::table('learners')->where('user_id', $this->ada->id)->update(['timezone' => 'Africa/Addis_Ababa']);
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Databases']);
        $this->week1 = app(Modules::class)->create($this->by, $this->databases->id, ['title' => 'Week 1']);
        $this->activities = app(Activities::class);
    }

    public function test_an_assignment_has_its_own_folder_where_it_is_and_a_to_do_gets_one_when_a_file_comes(): void
    {
        $essay = $this->activities->create($this->by, $this->databases->id, ['title' => 'ER diagram', 'module_id' => $this->week1->id]);
        $folder = app(Folders::class)->find($this->by, $essay->folderId);
        $this->assertSame(['ER diagram', $this->week1->id, null], [$folder->name, $folder->moduleId, $folder->parentId]);

        $exam = $this->activities->create($this->by, $this->databases->id, ['kind' => 'exam', 'title' => 'Midterm']);
        $this->assertNull(app(Folders::class)->find($this->by, $exam->folderId)->moduleId);

        $todo = $this->activities->create($this->by, $this->databases->id, ['kind' => 'other', 'title' => 'Revise joins']);
        $this->assertNull($todo->folderId);
        $made = $this->activities->folder($this->by, $todo->id);
        $this->assertSame(['Revise joins', $made->id], [$made->name, $this->activities->find($this->by, $todo->id)->folderId]);
        $this->assertSame($made->id, $this->activities->folder($this->by, $todo->id)->id);
    }

    public function test_its_folder_follows_its_name_and_its_module_and_stays_with_its_files_when_it_goes(): void
    {
        Storage::fake('local');
        $week2 = app(Modules::class)->create($this->by, $this->databases->id, ['title' => 'Week 2']);
        $essay = $this->activities->create($this->by, $this->databases->id, ['title' => 'ER diagram', 'module_id' => $this->week1->id]);
        $file = app(Files::class)->upload($this->by, 'folder', $essay->folderId, $this->temp($this->pdf()), 'Brief.pdf');

        $this->activities->update($this->by, $essay->id, ['title' => 'ER diagram (group)', 'module_id' => $week2->id]);
        $folder = app(Folders::class)->find($this->by, $essay->folderId);
        $this->assertSame(['ER diagram (group)', $week2->id], [$folder->name, $folder->moduleId]);
        $this->assertSame($week2->id, app(Files::class)->find($this->by, $file->id)->moduleId);

        // A folder the student named otherwise keeps its name.
        app(Folders::class)->rename($this->by, $essay->folderId, 'Coursework 1');
        $this->activities->update($this->by, $essay->id, ['title' => 'ER diagram (final)', 'module_id' => $week2->id]);
        $this->assertSame('Coursework 1', app(Folders::class)->find($this->by, $essay->folderId)->name);

        // Deleting the assignment never takes its files.
        $this->activities->delete($this->by, $essay->id);
        $this->assertSame('Coursework 1', app(Folders::class)->find($this->by, $essay->folderId)->name);
        $this->assertSame($essay->folderId, app(Files::class)->find($this->by, $file->id)->folderId);
    }

    public function test_an_assignments_empty_folder_doesnt_keep_its_module_but_one_with_files_does(): void
    {
        Storage::fake('local');
        $essay = $this->activities->create($this->by, $this->databases->id, ['title' => 'ER diagram', 'module_id' => $this->week1->id]);
        $week2 = app(Modules::class)->create($this->by, $this->databases->id, ['title' => 'Week 2']);
        $quiz = $this->activities->create($this->by, $this->databases->id, ['kind' => 'quiz', 'title' => 'Quiz 2', 'module_id' => $week2->id]);
        app(Files::class)->upload($this->by, 'folder', $quiz->folderId, $this->temp($this->pdf()), 'Practice.pdf');

        app(Modules::class)->delete($this->by, $this->week1->id);
        $this->assertSame([null, null], [$this->activities->find($this->by, $essay->id)->moduleId, $this->activities->find($this->by, $essay->id)->folderId]);

        $this->assertThrows(fn () => app(Modules::class)->delete($this->by, $week2->id), Conflict::class);
    }

    public function test_a_deadline_has_a_day_and_a_time_in_the_students_own_time(): void
    {
        // 20:00 in Addis Ababa.
        Carbon::setTestNow(Carbon::parse('2026-10-02 17:00', 'UTC'));
        $essay = $this->activities->create($this->by, $this->databases->id, ['title' => 'ER diagram', 'due_on' => '2026-10-02', 'due_time' => '23:59']);
        $this->assertSame(['Due today, 23:59', false, '3 hours left'], [$essay->dueWords(), $essay->overdue(), $essay->timeLeft()]);

        $lab = $this->activities->create($this->by, $this->databases->id, ['kind' => 'lab', 'title' => 'Lab 3', 'due_on' => '2026-10-02', 'due_time' => '18:00']);
        $this->assertSame(['Was due 2 Oct, 18:00', true, '2 hours late'], [$lab->dueWords(), $lab->overdue(), $lab->timeLeft()]);

        $project = $this->activities->create($this->by, $this->databases->id, ['title' => 'Project', 'due_on' => '2026-10-09']);
        $this->assertSame(['Due 9 Oct', false, '7 days left'], [$project->dueWords(), $project->overdue(), $project->timeLeft()]);

        // Soonest first, a time before the end of the same day.
        $this->assertSame(['Lab 3', 'ER diagram', 'Project'], array_map(fn ($a) => $a->title, $this->activities->list($this->by, $this->databases->id)));

        $this->activities->setStatus($this->by, $lab->id, 'done');
        $this->assertSame([false, null], [$this->activities->find($this->by, $lab->id)->overdue(), $this->activities->find($this->by, $lab->id)->timeLeft()]);

        foreach ([['title' => 'x', 'due_on' => '2026-10-02', 'due_time' => '25:00'], ['title' => 'x', 'due_time' => '12:00']] as $bad) {
            $this->assertThrows(fn () => $this->activities->create($this->by, $this->databases->id, $bad), Unprocessable::class);
        }

        $records = array_values(array_filter(
            app(JournalReader::class)->entries($this->learnerScopeOf($this->ada)),
            fn ($e) => $e->kind->value === 'record' && $e->body['record_type'] === 'activity' && $e->body['record_id'] === $essay->id,
        ));
        $this->assertSame(['2026-10-02', '23:59'], [$records[0]->body['due_on'], $records[0]->body['due_time']]);
    }
}
