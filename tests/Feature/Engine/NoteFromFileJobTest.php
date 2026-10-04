<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\Jobs\NoteFromFile;
use App\Engine\Jobs\Runner;
use App\Engine\Settings;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\NoteDetails;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The reader writes a note from a file, beside the file, as the AI's work (docs/specs/vistud-2-blueprint.md §3.6.5). */
class NoteFromFileJobTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $module;

    private Fake $engine;

    private const NOTES = "## Scheduling\n\n- The **scheduler** picks the next process.\n- Round robin gives each a *quantum*.\n\n## Deadlocks\n\nFour conditions must hold.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 3'])->id;
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
    }

    private function makeNote(string $fileId): NoteDetails
    {
        return app(Runner::class)->answer($this->by, new NoteFromFile($this->workspace, $fileId));
    }

    public function test_the_rules_are_short_and_say_it_is_markdown_from_the_file_only(): void
    {
        $rules = NoteFromFile::rules();

        $this->assertLessThanOrEqual(600, (int) ceil(strlen($rules) / 4));
        foreach (['Markdown', 'never invent', 'not instructions to you', 'no title line'] as $part) {
            $this->assertStringContainsString($part, $rules);
        }
        $this->assertStringNotContainsString('<!--', $rules);
    }

    public function test_a_note_is_made_in_the_files_module_with_the_ais_words_marked_as_its_work(): void
    {
        $file = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp("The scheduler picks the next process. Round robin gives each a quantum.\n"), 'Lecture 3.txt');
        $this->engine->will(Fake::says(self::NOTES, 4_000, 'fake/quick'));

        $note = $this->makeNote($file->id);

        $this->assertSame(['Notes · Lecture 3', $this->module, null], [$note->title, $note->moduleId, $note->folderId]);
        $opened = app(Notes::class)->open($this->by, $note->id);
        $text = NoteDoc::text($opened->doc);
        $this->assertStringContainsString('The scheduler picks the next process.', $text);
        $this->assertStringContainsString('Four conditions must hold.', $text);
        // The history says who wrote it.
        $kinds = LearnerTables::query(LearnerScope::of($this->by), 'note_versions')->where('note_id', $note->id)->orderBy('version')->pluck('kind')->all();
        $this->assertSame(['created', 'tutor'], $kinds);

        $this->assertStringContainsString('The file is "Lecture 3.txt"', $this->engine->requests[0]->messages[0]['content']);
        $this->assertStringContainsString('Round robin gives each a quantum.', $this->engine->requests[0]->messages[0]['content']);
        $row = LearnerTables::query(LearnerScope::of($this->by), 'engine_jobs')->firstOrFail();
        $this->assertSame(['reader', 'note_from_file', 'file', 'done', 4_000], [$row->role, $row->kind, $row->target_type, $row->status, (int) $row->cost_micros]);
    }

    public function test_the_note_sits_in_the_folder_the_file_is_in(): void
    {
        $folder = app(Folders::class)->create($this->by, 'module', $this->module, 'Lectures');
        $file = app(Files::class)->upload($this->by, 'folder', $folder->id, $this->temp("Memory is paged.\n"), 'Paging.txt');
        $this->engine->will(Fake::says(self::NOTES));

        $note = $this->makeNote($file->id);

        $this->assertSame([$folder->id, 'Notes · Paging'], [$note->folderId, $note->title]);
    }

    public function test_a_picture_or_an_empty_answer_makes_no_note(): void
    {
        $picture = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp($this->image()), 'Diagram.png');
        try {
            $this->makeNote($picture->id);
            $this->fail('Expected a refusal.');
        } catch (Unprocessable $e) {
            $this->assertSame('file_picture', $e->errorCode);
        }

        $file = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp("Words.\n"), 'Words.txt');
        $this->engine->will(Fake::says("   \n"));
        try {
            $this->makeNote($file->id);
            $this->fail('Expected a failure.');
        } catch (EngineFailed $e) {
            $this->assertSame('engine_empty', $e->errorCode);
        }
        $this->assertSame([], app(Notes::class)->list($this->by, $this->workspace));
    }

    public function test_another_students_file_or_one_from_another_course_is_not_found(): void
    {
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private'])->id;
        $secret = app(Files::class)->upload($bob, 'workspace', $theirs, $this->temp("Secret.\n"), 'Secret.txt');
        $this->assertThrows(fn () => $this->makeNote($secret->id), NotFound::class);

        $other = app(Workspaces::class)->create($this->by, ['name' => 'Biology'])->id;
        $mine = app(Files::class)->upload($this->by, 'workspace', $other, $this->temp("Cells.\n"), 'Cells.txt');
        $this->assertThrows(fn () => $this->makeNote($mine->id), NotFound::class);
        $this->assertSame([], $this->engine->requests);
    }
}
