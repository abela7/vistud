<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Livewire\Workspaces\Contents;
use App\Livewire\Workspaces\FileDigest;
use App\Livewire\Workspaces\TopicSheet;
use App\Livewire\Workspaces\TutorChat;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Study\FileDigests;
use App\Study\Files;
use App\Study\Findings;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\TopicSuggestions;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The module page as the working surface: tabs, what the reader found, files that say whether they are read, Study this ▾ and the topic sheet (docs/specs/vistud-2-blueprint.md §3.5.3). */
class ModulePageScreenTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $module;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 3: CPU scheduling', 'starts_on' => '2026-10-05', 'ends_on' => '2026-10-11'])->id;
    }

    private function page(array $params = [])
    {
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(Contents::class, ['workspaceId' => $this->workspace, 'view' => 'module', 'placeId' => $this->module] + $params);
    }

    private function ready(): void
    {
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
    }

    private function file(string $name = 'Lecture 3.txt', string $text = "Scheduling decides which process runs next.\n"): string
    {
        return app(Files::class)->upload($this->by, 'module', $this->module, $this->temp($text), $name)->id;
    }

    public function test_the_page_has_the_template_a_context_line_study_this_and_five_tabs(): void
    {
        $topics = app(Topics::class);
        $topics->report($this->by, $topics->create($this->by, $this->workspace, 'Processes', $this->module)->id, 'understood');
        $topics->create($this->by, $this->workspace, 'Scheduling', $this->module);
        app(Questions::class)->ask($this->by, $this->workspace, 'What is a quantum?', null, $this->module);

        $this->actingAs($this->ada)->get(route('workspaces.modules.show', [$this->workspace, $this->module]))->assertOk()
            ->assertSee('<title>Week 3: CPU scheduling · Operating Systems', false)
            ->assertSeeInOrder(['Operating Systems', 'Week 3: CPU scheduling', '5 Oct – 11 Oct · 1 of 2 understood'])
            ->assertSee('Back to </span>Modules', false)->assertSee('More for Week 3: CPU scheduling')
            ->assertSeeInOrder(['Edit module', 'Tell the tutor about this module', 'Delete module'])
            ->assertSeeInOrder(['Study this', 'Whole module', 'Pick a topic', 'Quiz me', 'Test me'])
            // The tabs, with the topic count and the open questions; Questions and Sessions are their own pages.
            ->assertSeeInOrder(['Topics', '2', 'Files', 'Notes', 'Questions', '1', 'Sessions'])
            ->assertSee('aria-current="page"', false)->assertSee(route('workspaces.modules.questions', [$this->workspace, $this->module]), false)
            ->assertSeeInOrder(['Processes', 'Understood', 'Scheduling', 'Not started'])->assertSee('Add a topic, like');

        $this->actingAs($this->ada)->get(route('workspaces.modules.sessions', [$this->workspace, $this->module]))->assertOk()
            ->assertSee('aria-label="This module"', false)->assertSee(route('workspaces.modules.show', [$this->workspace, $this->module, 'tab' => 'files']), false);
    }

    public function test_what_the_reader_found_is_one_line_with_add_all_pick_and_not_these(): void
    {
        $file = $this->file();
        app(TopicSuggestions::class)->suggest($this->by, $this->module, $file, ['CPU scheduling', 'Round robin', 'Deadlocks', 'Paging', 'Memory']);

        $page = $this->page()->assertSee('5 new topics found')->assertSee('in Lecture 3.txt')->assertSee('CPU scheduling, Round robin, Deadlocks, Paging…')
            ->assertSee('Add all')->assertSee('Pick')->assertSee('Not these')
            ->call('pickSuggested')->assertSet('picking', true)->assertSee('Add Round robin');

        $round = app(TopicSuggestions::class)->list($this->by, $this->module)[1];
        $page->call('addOneSuggested', $round->id)->assertDontSee('Add Round robin')->assertSee('4 new topics found')->assertSee('Round robin');
        $page->call('dismissOneSuggested', app(TopicSuggestions::class)->list($this->by, $this->module)[0]->id)->assertSee('3 new topics found');

        $page->call('addSuggested')->assertSee('3 topics added.')->assertSet('picking', false)->assertDontSee('new topics found');
        $this->assertSame(['Round robin', 'Deadlocks', 'Paging', 'Memory'], array_map(fn ($t) => $t->name, app(Topics::class)->list($this->by, $this->workspace)));

        // Not these: the rest is dismissed for good.
        app(TopicSuggestions::class)->suggest($this->by, $this->module, null, ['Semaphores']);
        $this->page()->assertSee('1 new topic found')->call('dismissSuggested')->assertDontSee('new topic');
        $this->assertSame(0, app(TopicSuggestions::class)->suggest($this->by, $this->module, null, ['Semaphores']));
    }

    public function test_files_say_whether_the_ai_has_read_them_and_the_summary_and_outline_are_one_tap_away(): void
    {
        $read = $this->file();
        $fresh = $this->file('Lecture 4.txt', "Another.\n");
        $busy = $this->file('Lecture 5.txt', "Being read.\n");
        $picture = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp($this->image()), 'Whiteboard.png')->id;
        app(FileDigests::class)->keep($this->by, $read, ['summary' => 'Scheduling policies and their trade-offs.', 'outline' => [['page' => 1, 'heading' => 'Introduction'], ['page' => 4, 'heading' => 'Round robin']], 'topics' => ['CPU scheduling', 'Round robin'], 'language' => 'English'], 8, 1_000, 'fake/quick');
        LearnerTables::insert(LearnerScope::of($this->by), 'engine_jobs', ['id' => 'job-1', 'role' => 'reader', 'kind' => 'read_file', 'target_type' => 'file', 'target_id' => $busy, 'status' => 'running', 'created_at' => now()]);

        $page = $this->page()->set('tab', 'files')
            ->assertSee('Read')->assertSee('Scheduling policies and their trade-offs.')->assertSee('CPU scheduling · Round robin')->assertSee('Round robin')
            ->assertSee('Reading…')->assertSeeHtml('wire:poll.3s')->assertSee('Read now');
        // A picture has nothing to read: no chip beside it, and only the unread file offers Read now.
        $this->assertSame(1, substr_count($page->html(), "wire:click=\"readFile('{$fresh}')\""));
        $this->assertSame(0, substr_count($page->html(), "readFile('{$picture}')"));
        $this->assertSame(0, substr_count($page->html(), "readFile('{$read}')"));
        $page->assertSee('Upload files')->assertSee('New folder')->assertSee('Add a link')->assertDontSee('New note');
    }

    public function test_read_now_reads_the_file_or_says_what_is_missing(): void
    {
        $file = $this->file();
        $page = $this->page()->set('tab', 'files');

        // No AI set up: one line, no run.
        $page->call('readFile', $file)->assertSee('Add your OpenRouter key in your AI settings first.');
        $this->assertSame(0, LearnerTables::query(LearnerScope::of($this->by), 'engine_jobs')->count());

        $this->ready();
        $this->engine->will(Fake::says(json_encode(['summary' => 'Scheduling.', 'outline' => [], 'topics' => ['CPU scheduling'], 'language' => 'English'])));
        $page->call('readFile', $file)->assertSee('Scheduling.');
        $this->assertSame(['CPU scheduling'], array_map(fn ($s) => $s->name, app(TopicSuggestions::class)->list($this->by, $this->module)));

        // Another student's module and file are not found, not read.
        $this->actingAs($this->student());
        try {
            Livewire::test(Contents::class, ['workspaceId' => $this->workspace, 'view' => 'module', 'placeId' => $this->module])->call('readFile', $file);
            $this->fail('Expected not found.');
        } catch (\Throwable $e) {
            $this->assertTrue($e instanceof NotFound || $e->getPrevious() instanceof NotFound);
        }
    }

    public function test_the_notes_tab_holds_the_modules_notes_and_nothing_else(): void
    {
        $this->file();
        app(Notes::class)->create($this->by, 'module', $this->module, 'Lecture 3 notes');

        $this->page()->set('tab', 'notes')->assertSee('Lecture 3 notes')->assertSee('New note')->assertDontSee('Lecture 3.txt')->assertDontSee('Upload files');
        $this->page()->set('tab', 'files')->assertSee('Lecture 3.txt')->assertDontSee('Lecture 3 notes');
        // An unknown tab is the first.
        $this->page()->set('tab', 'nonsense')->assertSee('Add a topic, like');
    }

    public function test_study_this_starts_with_no_dialog_a_topic_is_picked_from_a_sheet_and_quiz_and_test_carry_their_ask(): void
    {
        $topics = app(Topics::class);
        $processes = $topics->create($this->by, $this->workspace, 'Processes', $this->module)->id;
        $scheduling = $topics->create($this->by, $this->workspace, 'Scheduling', $this->module)->id;
        $topics->report($this->by, $processes, 'understood');

        $this->page()->call('studyModule')->assertDispatched('study-next', moduleId: $this->module)
            ->call('pickTopic')->assertDispatched('topic-picker-open')->assertSee('Pick a topic')->assertSee('Study Scheduling')
            ->call('studyTopic', $scheduling)->assertDispatched('study-next', moduleId: $this->module, topicId: $scheduling)
            // Quiz me: on the next topic the student doesn't understand yet; Test me: on the whole module.
            ->call('studyAsk', 'quiz')->assertDispatched('study-next', moduleId: $this->module, topicId: $scheduling, ask: 'quiz')
            ->call('studyAsk', 'test')->assertDispatched('study-next', moduleId: $this->module, topicId: null, ask: 'test')
            ->call('studyAsk', 'nonsense')->assertNotDispatched('study-next', moduleId: $this->module, topicId: null, ask: 'nonsense');
    }

    public function test_the_ask_from_quiz_me_or_test_me_waits_in_the_chat_box_for_the_student_to_send(): void
    {
        $topic = app(Topics::class)->create($this->by, $this->workspace, 'Scheduling', $this->module)->id;
        $session = app(Sessions::class)->start($this->by, $this->workspace, $topic, $this->module);
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        Livewire::withQueryParams(['ask' => 'quiz'])->test(TutorChat::class, ['workspaceId' => $this->workspace, 'sessionId' => $session->id])->assertSet('text', 'Quiz me on Scheduling.');
        Livewire::withQueryParams(['ask' => 'test'])->test(TutorChat::class, ['workspaceId' => $this->workspace, 'sessionId' => $session->id])->assertSet('text', 'Test me on this whole module: ten exam-level questions, one at a time, and score me at the end.');
        Livewire::withQueryParams([])->test(TutorChat::class, ['workspaceId' => $this->workspace, 'sessionId' => $session->id])->assertSet('text', '');
    }

    public function test_a_topic_sheet_shows_where_the_student_stands_and_renames_moves_and_studies(): void
    {
        $other = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 4'])->id;
        $topic = app(Topics::class)->create($this->by, $this->workspace, 'Scheduling', $this->module)->id;
        app(Findings::class)->add($this->by, $topic, ['text' => 'Round robin gives each process a quantum.']);
        app(Flashcards::class)->add($this->by, $this->workspace, $topic, 'What is a quantum?', 'A time slice.');
        app(Questions::class)->ask($this->by, $this->workspace, 'Why does round robin starve long jobs?', $topic, $this->module);

        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        $sheet = Livewire::test(TopicSheet::class, ['workspaceId' => $this->workspace])->dispatch('topic-sheet-open', topicId: $topic)
            ->assertSet('topicId', $topic)->assertDispatched('topic-sheet-dialog-open')
            ->assertSee('Scheduling')->assertSee('Where you stand')->assertSee('Covered')->assertSee('Understood')->assertSee('Still confusing')
            ->assertSee('1 card, 1 due')->assertSee('Why does round robin starve long jobs?')->assertSee('Round robin gives each process a quantum.');

        $sheet->call('report', 'confused')->assertSee('Saved.')->assertDispatched('topics-changed');
        $this->assertSame('confused', app(Topics::class)->find($this->by, $topic)->status);
        $sheet->call('report', 'mastered')->assertHasErrors('status');

        $sheet->set('name', 'CPU scheduling')->call('rename')->assertSee('Renamed.');
        $this->assertSame('CPU scheduling', app(Topics::class)->find($this->by, $topic)->name);
        $sheet->set('name', '')->call('rename')->assertHasErrors('name');

        $sheet->call('study')->assertDispatched('study-next', moduleId: $this->module, topicId: $topic);
        $sheet->set('moduleId', $other)->call('move')->assertDispatched('topics-changed')->assertSet('topicId', null);
        $this->assertSame($other, app(Topics::class)->find($this->by, $topic)->moduleId);
    }

    public function test_another_students_topic_cannot_be_opened_in_the_sheet(): void
    {
        $topic = app(Topics::class)->create($this->by, $this->workspace, 'Scheduling', $this->module)->id;
        $this->actingAs($this->student());
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        $this->expectException(NotFound::class);
        Livewire::test(TopicSheet::class, ['workspaceId' => $this->workspace])->dispatch('topic-sheet-open', topicId: $topic);
    }

    public function test_all_notes_and_files_can_be_searched_across_the_course(): void
    {
        $this->file('Lecture 3.txt');
        app(Notes::class)->create($this->by, 'module', $this->module, 'Round robin notes');
        app(Notes::class)->create($this->by, 'workspace', $this->workspace, 'Exam checklist');
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        $page = Livewire::test(Contents::class, ['workspaceId' => $this->workspace, 'view' => 'notes'])->assertSee('Exam checklist')->assertDontSee('Search results');
        $page->set('search', 'round')->assertSee('Search results')->assertSee('Round robin notes')->assertSee('Week 3: CPU scheduling')->assertDontSee('Exam checklist');
        $page->set('search', 'lecture txt')->assertSee('Lecture 3.txt')->assertDontSee('Round robin notes');
        $page->set('search', 'zzz')->assertSee('Nothing matches');
        $page->set('search', '')->assertSee('Exam checklist');

        // The Modules page has the link and the search box that go there.
        $this->get(route('workspaces.show', [$this->workspace, 'modules']))->assertOk()
            ->assertSee('All notes &amp; files', false)->assertSee('role="search"', false)->assertSee('name="q"', false);
    }

    public function test_a_files_page_says_what_the_ai_read_and_reads_it_on_request(): void
    {
        $file = $this->file();
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        $this->get(route('workspaces.files.show', [$this->workspace, $file]))->assertOk()->assertSee('Read by the AI')->assertSee("hasn't read this file yet", false)->assertSee('Read now');

        $panel = Livewire::test(FileDigest::class, ['fileId' => $file])->assertSee('Read now');
        // No AI set up: a line says what to do, and nothing runs.
        $panel->call('readNow')->assertSee('Add your OpenRouter key in your AI settings first.');
        $this->assertSame(0, LearnerTables::query(LearnerScope::of($this->by), 'engine_jobs')->count());

        $this->ready();
        $this->engine->will(Fake::says(json_encode(['summary' => 'Scheduling decides who runs.', 'outline' => [['page' => 2, 'heading' => 'Policies']], 'topics' => ['CPU scheduling'], 'language' => 'English'])));
        $panel->call('readNow')->assertSee('Scheduling decides who runs.')->assertSee('CPU scheduling')->assertSee('Policies')->assertDontSee('Read now')->assertDontSeeHtml('wire:poll');

        // Being read: it says so and asks again every few seconds.
        $busy = $this->file('Lecture 4.txt', "Another.\n");
        LearnerTables::insert(LearnerScope::of($this->by), 'engine_jobs', ['id' => 'job-2', 'role' => 'reader', 'kind' => 'read_file', 'target_type' => 'file', 'target_id' => $busy, 'status' => 'running', 'created_at' => now()]);
        Livewire::test(FileDigest::class, ['fileId' => $busy])->assertSee('Reading…')->assertSeeHtml('wire:poll.3s');

        // A picture is never sent, so its page has no such block.
        $picture = app(Files::class)->upload($this->by, 'module', $this->module, $this->temp($this->image()), 'Whiteboard.png')->id;
        Livewire::test(FileDigest::class, ['fileId' => $picture])->assertDontSee('Read by the AI');

        // Another student's file is not found.
        $this->actingAs($this->student());
        try {
            Livewire::test(FileDigest::class, ['fileId' => $file]);
            $this->fail('Expected not found.');
        } catch (\Throwable $e) {
            $this->assertTrue($e instanceof NotFound || $e->getPrevious() instanceof NotFound);
        }
    }
}
