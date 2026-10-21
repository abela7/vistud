<?php

namespace Tests\Feature\Engine;

use App\Engine\Context\Stack;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Jobs\ChooseTopics;
use App\Engine\Jobs\ReadFile;
use App\Engine\Jobs\Runner;
use App\Engine\Settings;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Ids;
use App\Study\CourseProfiles;
use App\Study\FileDetails;
use App\Study\FileDigests;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\TopicSuggestions;
use App\Study\Workspaces;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * The main topics of a part of a module are chosen from all of its files at once (the owner's ask, 2026-10-12): which
 * files are study material, a few main topics in the order to study them, each with why it matters, what it covers and
 * where it is taught; the student accepts them one by one or all together.
 */
class ChooseTopicsTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private Principal $by;

    private string $workspace;

    private string $module;

    private string $lecture;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->by = $this->principal($this->student());
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        app(CourseProfiles::class)->save($this->by, $this->workspace, ['about' => 'How operating systems manage processes and memory.', 'outcomes' => ['Explain how an OS schedules processes']]);
        $this->module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 3', 'about' => 'Lecture and lab week on scheduling.'])->id;
        $this->lecture = app(Folders::class)->create($this->by, 'module', $this->module, 'Lecture', 'lecture')->id;
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->ownAiKey($this->by);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
    }

    /** A file in the lecture folder, read into a digest (the reader's work is ReadFileJobTest's). */
    private function readFile(string $name, string $summary, array $topics, array $outline = []): FileDetails
    {
        $file = app(Files::class)->upload($this->by, 'folder', $this->lecture, $this->temp("{$summary}\n"), $name);
        app(FileDigests::class)->keep($this->by, $file->id, ['summary' => $summary, 'outline' => $outline, 'topics' => $topics, 'language' => 'English'], 12, 900, 'fake/quick');

        return $file;
    }

    private function choose(): void
    {
        app(Runner::class)->start($this->by, new ChooseTopics($this->workspace, 'folder', $this->lecture));
    }

    private function job(): object
    {
        return LearnerTables::query(LearnerScope::of($this->by), 'engine_jobs')->where('kind', 'choose_topics')->orderByDesc('created_at')->firstOrFail();
    }

    public function test_the_ai_reads_all_of_a_parts_files_and_chooses_a_few_main_topics_each_with_why(): void
    {
        $slides = $this->readFile('Lecture 3.txt', 'Scheduling: FCFS, SJF, round robin, priorities.', ['CPU scheduling', 'FCFS', 'SJF', 'Round robin', 'Quantum size', 'Priority scheduling', 'Starvation'], [['page' => 4, 'heading' => 'FCFS'], ['page' => 9, 'heading' => 'Round robin']]);
        $admin = $this->readFile('Module handbook.txt', 'Deadlines, marking scheme and office hours.', ['Deadlines', 'Office hours']);
        app(Topics::class)->create($this->by, $this->workspace, 'Processes', $this->module);
        $this->engine->will(Fake::says(json_encode(['files' => [
            ['file' => 'F1', 'use' => 'core', 'why' => 'The lecture itself.'],
            ['file' => 'F2', 'use' => 'skip', 'why' => 'Course admin, not study material.'],
            ['file' => 'F9', 'use' => 'core', 'why' => 'Not a file.'],
        ], 'topics' => [
            ['name' => 'CPU scheduling', 'why' => 'Lets you compare how an OS shares the CPU, which the coursework asks for.', 'covers' => ['FCFS', 'SJF', 'cpu scheduling'], 'files' => ['F1'], 'pages' => '3–8'],
            ['name' => 'Round robin', 'why' => 'The algorithm most systems use, and an exam favourite.', 'covers' => ['Quantum size', 'Starvation', 'Context switch cost', 'Fairness', 'Response time', 'Overhead'], 'files' => ['F1'], 'pages' => '9–14'],
            ['name' => 'Office hours', 'why' => 'When to ask.', 'covers' => [], 'files' => ['F2']],
            ['name' => 'Processes', 'why' => 'Already there.', 'covers' => [], 'files' => ['F1']],
        ]])));

        $this->choose();

        // One call for the whole part, on the tutor's model, given the course, the module, the part and each file's digest.
        $request = $this->engine->requests[0];
        $this->assertSame('fake/tutor', $request->model);
        $this->assertStringContainsString('You choose the main topics of a part of a course', $request->system);
        $sent = is_string($request->messages[0]['content']) ? $request->messages[0]['content'] : '';
        foreach (['Course: Operating Systems', 'About it: How operating systems manage processes', 'Module: Week 3 (Lecture and lab week on scheduling.)', 'This part: Lecture (lecture)', 'already has in this module: Processes', 'F1: Lecture 3.txt (12 pages)', 'Headings: p4 FCFS; p9 Round robin', 'F2: Module handbook.txt'] as $part) {
            $this->assertStringContainsString($part, $sent);
        }

        // Main topics only, in order, each with why, what it covers (five at most, not itself) and where; the admin file's
        // topic and the one already there are left out.
        $waiting = app(TopicSuggestions::class)->list($this->by, $this->module);
        $this->assertSame(['CPU scheduling', 'Round robin'], array_map(fn ($s) => $s->name, $waiting));
        $this->assertSame([1, 2], array_map(fn ($s) => $s->position, $waiting));
        $this->assertSame('Lets you compare how an OS shares the CPU, which the coursework asks for.', $waiting[0]->reason);
        $this->assertSame(['FCFS', 'SJF'], $waiting[0]->covers);
        $this->assertCount(5, $waiting[1]->covers);
        $this->assertSame([['fileId' => $slides->id, 'name' => 'Lecture 3.txt', 'pages' => '3–8']], $waiting[0]->sources);
        $this->assertSame($this->lecture, $waiting[0]->folderId);

        // What the AI made of each file is kept to show beside them.
        $review = ChooseTopics::review($this->by, $this->module, $this->lecture);
        $this->assertSame([[$slides->id, 'core', 'Lecture 3.txt'], [$admin->id, 'skip', 'Module handbook.txt']], array_map(fn ($f) => [$f['file'], $f['use'], $f['name']], $review['files']));
        $this->assertSame('Course admin, not study material.', $review['files'][1]['why']);

        // Accepted, a suggestion is a topic in the folder; one said no to is never chosen again.
        app(TopicSuggestions::class)->add($this->by, $waiting[0]->id);
        app(TopicSuggestions::class)->dismiss($this->by, $waiting[1]->id);
        $this->engine->will(Fake::says(json_encode(['files' => [], 'topics' => [
            ['name' => 'round robin', 'why' => 'x', 'covers' => [], 'files' => ['F1']],
            ['name' => 'Priority scheduling', 'why' => 'Real systems mix priorities in.', 'covers' => [], 'files' => ['F1']],
        ]])));
        $this->choose();
        $this->assertSame(['Priority scheduling'], array_map(fn ($s) => $s->name, app(TopicSuggestions::class)->list($this->by, $this->module)));
        $this->assertStringContainsString('Topics they said no to: Round robin', $this->engine->requests[1]->messages[0]['content']);
        $topic = collect(app(Topics::class)->list($this->by, $this->workspace))->firstWhere('name', 'CPU scheduling');
        $this->assertSame($this->lecture, $topic->folderId);
    }

    public function test_each_topic_has_the_ais_note_and_a_twin_of_one_there_is_never_chosen(): void
    {
        $this->readFile('Lecture 3.txt', 'Processes, scheduling and paging.', ['Processes', 'Scheduling', 'Paging']);
        app(Topics::class)->create($this->by, $this->workspace, 'The process', $this->module);
        $lab = app(Folders::class)->create($this->by, 'module', $this->module, 'Lab', 'lab');
        app(TopicSuggestions::class)->choose($this->by, $this->module, $lab->id, [['name' => 'Paging', 'why' => '', 'covers' => [], 'sources' => []]]);
        $this->engine->will(Fake::says(json_encode(['files' => [['file' => 'F1', 'use' => 'core', 'why' => 'The lecture.']], 'topics' => [
            ['name' => 'Processes', 'why' => 'x', 'note' => 'x', 'files' => ['F1']],
            ['name' => 'CPU Scheduling', 'why' => 'How the OS shares the CPU.', 'note' => 'Pages 4–20 of F1 teach it, and the course outcomes name it. Folded in: FCFS, round robin.', 'files' => ['F1']],
            ['name' => 'cpu-scheduling!', 'why' => 'Twice.', 'files' => ['F1']],
            ['name' => 'The pagings', 'why' => 'Waiting in the lab already.', 'files' => ['F1']],
        ]])));

        $this->choose();

        // "Processes" is "The process" there already; "cpu-scheduling!" is a twin; "The pagings" waits in the lab.
        $here = app(TopicSuggestions::class)->list($this->by, $this->module, [$this->lecture]);
        $this->assertSame([['CPU Scheduling', 'Pages 4–20 of Lecture 3.txt teach it, and the course outcomes name it. Folded in: FCFS, round robin.']], array_map(fn ($s) => [$s->name, $s->note], $here));
        $sent = $this->engine->requests[0]->messages[0]['content'];
        $this->assertStringContainsString('Suggested in other parts of this module: Paging', $sent);
        $this->assertSame('graph traversal', TopicSuggestions::core('The Graph Traversals'));
    }

    public function test_asked_again_it_is_given_its_last_choice_and_what_the_student_said(): void
    {
        $this->readFile('Lecture 3.txt', 'Graphs: BFS, DFS, shortest paths.', ['Graphs', 'BFS', 'DFS']);
        $this->engine->will(Fake::says(json_encode(['files' => [['file' => 'F1', 'use' => 'core', 'why' => '']], 'topics' => [['name' => 'Graphs', 'why' => 'Broad.', 'files' => ['F1']]]])));
        $this->choose();

        // Not happy: too broad. Asked again with that, the AI is given its last choice and the student's words.
        ChooseTopics::again($this->by, $this->module, $this->lecture, 'Too broad: split Graphs into BFS and DFS.');
        $this->engine->will(Fake::says(json_encode(['files' => [], 'topics' => [
            ['name' => 'Breadth-first search', 'why' => 'Shortest paths in unweighted graphs.', 'note' => 'Split from Graphs, as asked.', 'files' => ['F1']],
            ['name' => 'Depth-first search', 'why' => 'Cycles and ordering.', 'note' => 'Split from Graphs, as asked.', 'files' => ['F1']],
        ]])));
        $this->choose();

        $sent = $this->engine->requests[1]->messages[0]['content'];
        $this->assertStringContainsString('Your last choice for this part, still waiting for the student: Graphs', $sent);
        $this->assertStringContainsString('What the student says about your last choice (their words, between the quotes): """Too broad: split Graphs into BFS and DFS."""', $sent);
        $this->assertSame(['Breadth-first search', 'Depth-first search'], array_map(fn ($s) => $s->name, app(TopicSuggestions::class)->list($this->by, $this->module)));

        // The words are used once. Asked again and the AI fails: what waits stays as it was, nothing is added to it.
        $this->engine->will(Fake::says('Sorry.'));
        $this->choose();
        $this->assertStringNotContainsString('What the student says', $this->engine->requests[2]->messages[0]['content']);
        $this->assertSame(['Breadth-first search', 'Depth-first search'], array_map(fn ($s) => $s->name, app(TopicSuggestions::class)->list($this->by, $this->module)));
    }

    public function test_a_topic_added_by_hand_is_one_like_any_other_and_takes_the_place_of_its_waiting_twin(): void
    {
        $this->readFile('Lecture 3.txt', 'Scheduling and paging.', ['Scheduling', 'Paging']);
        app(TopicSuggestions::class)->choose($this->by, $this->module, $this->lecture, [
            ['name' => 'CPU scheduling', 'why' => 'x', 'covers' => [], 'sources' => []],
            ['name' => 'Page replacement', 'why' => 'x', 'covers' => [], 'sources' => []],
        ]);
        app(TopicSuggestions::class)->add($this->by, app(TopicSuggestions::class)->list($this->by, $this->module)[0]->id);

        // Two by hand: one new, one the twin of the suggestion still waiting.
        app(Topics::class)->create($this->by, $this->workspace, 'Deadlocks', $this->module, $this->lecture);
        app(Topics::class)->create($this->by, $this->workspace, 'The page replacements', $this->module, $this->lecture);

        $this->assertSame([], app(TopicSuggestions::class)->list($this->by, $this->module), 'the twin no longer waits');
        $mine = array_values(array_filter(app(Topics::class)->list($this->by, $this->workspace), fn ($t) => $t->moduleId === $this->module));
        $this->assertSame(['CPU scheduling', 'Deadlocks', 'The page replacements'], array_map(fn ($t) => $t->name, $mine));
        $this->assertSame([$this->lecture, $this->lecture, $this->lecture], array_map(fn ($t) => $t->folderId, $mine));

        // The tutor in the folder sees all three at once, and the next choice is told the student has them.
        $session = app(Sessions::class)->start($this->by, $this->workspace, null, $this->module, folderId: $this->lecture);
        $system = app(Stack::class)->build($this->by, $session, null, true)->system;
        foreach (['CPU scheduling', 'Deadlocks', 'The page replacements'] as $name) {
            $this->assertStringContainsString($name, $system);
        }
        $this->engine->will(Fake::says(json_encode(['files' => [], 'topics' => [['name' => 'Deadlock', 'why' => 'x', 'files' => ['F1']]]])));
        $this->choose();
        $this->assertStringContainsString('already has in this module: CPU scheduling; Deadlocks; The page replacements', $this->engine->requests[0]->messages[0]['content']);
        $this->assertSame([], app(TopicSuggestions::class)->list($this->by, $this->module), '"Deadlock" is "Deadlocks", which is there');
    }

    public function test_it_waits_while_another_file_of_the_part_is_being_read(): void
    {
        $this->readFile('Lecture 3.txt', 'Scheduling.', ['CPU scheduling']);
        $unread = app(Files::class)->upload($this->by, 'folder', $this->lecture, $this->temp("Lab sheet.\n"), 'Lab 3.txt');
        LearnerTables::insert(LearnerScope::of($this->by), 'engine_jobs', ['id' => Ids::new(), 'role' => 'reader', 'kind' => 'read_file', 'workspace_id' => $this->workspace, 'target_type' => 'file', 'target_id' => $unread->id, 'status' => 'running', 'created_at' => now()]);

        $this->choose();

        $this->assertSame(['skipped', 'still_reading'], [$this->job()->status, $this->job()->error_code]);
        $this->assertSame([], $this->engine->requests);
    }

    public function test_when_nothing_can_be_chosen_what_the_reader_saw_waits_instead(): void
    {
        $this->readFile('Lecture 3.txt', 'Scheduling.', ['CPU scheduling', 'Round robin']);
        $this->engine->will(Fake::says('Sorry, I cannot.'));

        $this->choose();

        $this->assertSame('failed', $this->job()->status);
        $this->assertSame(['CPU scheduling', 'Round robin'], array_map(fn ($s) => $s->name, app(TopicSuggestions::class)->list($this->by, $this->module)));
    }

    public function test_a_read_file_starts_the_choosing_of_its_parts_topics(): void
    {
        $this->engine->will(
            Fake::says(json_encode(['summary' => 'Scheduling.', 'outline' => [], 'topics' => ['CPU scheduling'], 'language' => 'English'])),
            Fake::says(json_encode(['files' => [['file' => 'F1', 'use' => 'core', 'why' => 'The lecture.']], 'topics' => [['name' => 'CPU scheduling', 'why' => 'Core of the week.', 'covers' => ['FCFS'], 'files' => ['F1'], 'pages' => '']]])),
        );
        $inner = app(Folders::class)->create($this->by, 'folder', $this->lecture, 'Slides');
        $file = app(Files::class)->upload($this->by, 'folder', $inner->id, $this->temp("Scheduling decides which process runs.\n"), 'Lecture 3.txt');

        app(Runner::class)->start($this->by, new ReadFile($this->workspace, $file->id));

        // Chosen for the folder at the top of the module that holds the file.
        $this->assertSame(['folder', $this->lecture, 'done'], [$this->job()->target_type, $this->job()->target_id, $this->job()->status]);
        $waiting = app(TopicSuggestions::class)->list($this->by, $this->module);
        $this->assertSame([['CPU scheduling', 'Core of the week.']], array_map(fn ($s) => [$s->name, $s->reason], $waiting));
    }
}
