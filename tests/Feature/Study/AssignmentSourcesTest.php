<?php

namespace Tests\Feature\Study;

use App\Engine\Settings;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Activities;
use App\Study\ActivityDetails;
use App\Study\AssignmentSources;
use App\Study\AssignmentUnderstandings;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Everything a student is given about an assignment: what they write or paste in, and the files in its folder. */
class AssignmentSourcesTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private ActivityDetails $assignment;

    private AssignmentSources $sources;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        app(Settings::class)->set($this->by, ['auto_read_files' => false]);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Databases'])->id;
        $module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 2: SQL joins'])->id;
        $this->assignment = app(Activities::class)->create($this->by, $this->workspace, ['kind' => 'assignment', 'title' => 'Joins report', 'module_id' => $module]);
        $this->sources = app(AssignmentSources::class);
    }

    private function refused(callable $try): array
    {
        try {
            $try();
            $this->fail('Expected a refusal.');
        } catch (Unprocessable $e) {
            return array_keys($e->details['fields'] ?? []);
        }
    }

    public function test_a_source_keeps_its_lines_and_is_checked(): void
    {
        $brief = $this->sources->add($this->by, $this->assignment->id, ['kind' => 'rubric', 'text' => "Analysis   \r\n\r\n\r\n\r\n40%\r\nWriting 30%", 'received_on' => '2026-10-01']);

        // An email or a rubric is read as it was written: only spaces at line ends and runs of empty lines go.
        $this->assertSame(["Analysis\n\n40%\nWriting 30%", 'Marking criteria', 'Marking criteria', '2026-10-01'], [$brief->text, $brief->kindLabel(), $brief->name(), $brief->receivedOn]);
        $this->assertSame([$brief->id], array_map(fn ($s) => $s->id, $this->sources->list($this->by, $this->assignment->id)));

        $email = $this->sources->update($this->by, $brief->id, ['kind' => 'email', 'title' => 'Deadline moved', 'text' => 'Now due on the 24th.']);
        $this->assertSame(['email', 'Deadline moved', null], [$email->kind, $email->name(), $email->receivedOn]);

        $this->assertSame(['kind'], $this->refused(fn () => $this->sources->add($this->by, $this->assignment->id, ['kind' => 'gossip', 'text' => 'x'])));
        $this->assertSame(['text'], $this->refused(fn () => $this->sources->add($this->by, $this->assignment->id, ['kind' => 'brief', 'text' => "  \n "])));
        $this->assertSame(['text'], $this->refused(fn () => $this->sources->add($this->by, $this->assignment->id, ['text' => str_repeat('a', AssignmentSources::MAX_TEXT + 1)])));
        $this->assertSame(['received_on'], $this->refused(fn () => $this->sources->add($this->by, $this->assignment->id, ['text' => 'x', 'received_on' => '2026-02-30'])));
        $this->assertSame(['title'], $this->refused(fn () => $this->sources->add($this->by, $this->assignment->id, ['text' => 'x', 'title' => str_repeat('t', AssignmentSources::MAX_TITLE + 1)])));
    }

    public function test_the_files_in_its_folder_are_sources_too_and_any_change_changes_what_it_is_understood_from(): void
    {
        $files = app(Files::class);
        $before = $this->sources->fingerprint($this->by, $this->assignment->id);

        $brief = $this->sources->add($this->by, $this->assignment->id, ['text' => 'Write 1,500 words on joins.']);
        $written = $this->sources->fingerprint($this->by, $this->assignment->id);
        $this->assertNotSame($before, $written);

        // Its folder and the folders inside it (a section's) count; another folder doesn't.
        $section = app(Folders::class)->create($this->by, 'folder', (string) $this->assignment->folderId, 'Part A');
        $rubric = $files->upload($this->by, 'folder', (string) $this->assignment->folderId, $this->temp("Analysis 40%.\n"), 'Rubric.txt');
        $data = $files->upload($this->by, 'folder', $section->id, $this->temp("id,name\n"), 'Data.txt');
        $files->upload($this->by, 'workspace', $this->workspace, $this->temp("Other.\n"), 'Elsewhere.txt');
        $this->assertSame([$rubric->id, $data->id], array_map(fn ($f) => $f->id, $this->sources->files($this->by, $this->assignment->id)));
        $withFiles = $this->sources->fingerprint($this->by, $this->assignment->id);
        $this->assertNotSame($written, $withFiles);

        $this->travel(1)->seconds();
        $this->sources->update($this->by, $brief->id, ['text' => 'Write 2,000 words on joins.']);
        $edited = $this->sources->fingerprint($this->by, $this->assignment->id);
        $this->assertNotSame($withFiles, $edited);
        $this->sources->delete($this->by, $brief->id);
        $this->assertNotSame($edited, $this->sources->fingerprint($this->by, $this->assignment->id));
        $this->assertSame($this->sources->fingerprint($this->by, $this->assignment->id), $this->sources->fingerprint($this->by, $this->assignment->id), 'the same sources, the same fingerprint');
    }

    public function test_another_students_sources_are_not_found(): void
    {
        $brief = $this->sources->add($this->by, $this->assignment->id, ['text' => 'Mine.']);
        $grace = $this->principal($this->student());

        foreach ([
            fn () => $this->sources->list($grace, $this->assignment->id),
            fn () => $this->sources->files($grace, $this->assignment->id),
            fn () => $this->sources->add($grace, $this->assignment->id, ['text' => 'Theirs.']),
            fn () => $this->sources->update($grace, $brief->id, ['text' => 'Changed.']),
            fn () => $this->sources->delete($grace, $brief->id),
            fn () => app(AssignmentUnderstandings::class)->latest($grace, $this->assignment->id),
        ] as $try) {
            $this->assertThrows($try, NotFound::class);
        }
        $this->assertSame('Mine.', $this->sources->list($this->by, $this->assignment->id)[0]->text);
    }

    public function test_deleting_the_assignment_deletes_what_it_was_given_and_understood(): void
    {
        $this->sources->add($this->by, $this->assignment->id, ['text' => 'Write 1,500 words on joins.']);
        app(AssignmentUnderstandings::class)->keep($this->by, $this->assignment->id, ['summary' => 'A report.'], null, [], str_repeat('a', 40), 'fake/tutor');
        $section = app(Folders::class)->create($this->by, 'folder', (string) $this->assignment->folderId, 'Part A');
        app(Files::class)->upload($this->by, 'folder', $section->id, $this->temp("Analysis 40%.\n"), 'Rubric.txt');
        app(Notes::class)->create($this->by, 'folder', (string) $this->assignment->folderId, 'Draft');

        app(Activities::class)->delete($this->by, $this->assignment->id);

        $this->assertSame(
            [0, 0, 0, 0, 0, 0],
            [
                DB::table('activity_sources')->count(),
                DB::table('activity_understandings')->count(),
                DB::table('folders')->count(),
                DB::table('files')->count(),
                DB::table('notes')->count(),
                DB::table('note_versions')->count(),
            ]
        );
    }
}
