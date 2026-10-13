<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Jobs\ChooseTopics;
use App\Engine\Settings;
use App\Livewire\Workspaces\Contents;
use App\Livewire\Workspaces\Deck;
use App\Livewire\Workspaces\FlashcardReview;
use App\Livewire\Workspaces\Progress;
use App\Livewire\Workspaces\StudySession;
use App\Livewire\Workspaces\StudyTime;
use App\Livewire\Workspaces\TopicSheet;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\FileDigests;
use App\Study\Files;
use App\Study\Flashcards;
use App\Study\Folders;
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

/**
 * Studying by folder on the screens (docs/specs/vistud-2-blueprint.md, Phase 9): a folder's page is a place to study with
 * tabs of its own, a module's Topics tab shows its folders each with Study, the session in a folder says so, and the
 * cards, their review and Progress know folders.
 */
class FolderStudyScreenTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $week1;

    private string $first;

    private string $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->app->instance(Engine::class, new Fake);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->week1 = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 1: OS Structure | Processes & Threads'])->id;
        $this->first = app(Folders::class)->create($this->by, 'module', $this->week1, 'Lecture 1 + Lab 1')->id;
        $this->second = app(Folders::class)->create($this->by, 'module', $this->week1, 'Lecture 2 + Lab 2')->id;
    }

    private function as(): void
    {
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
    }

    private function folder(string $tab = 'topics')
    {
        $this->as();

        return Livewire::withQueryParams($tab === 'topics' ? [] : ['tab' => $tab])->test(Contents::class, ['workspaceId' => $this->workspace, 'view' => 'folder', 'placeId' => $this->first]);
    }

    public function test_a_folders_page_is_a_place_to_study_with_its_own_tabs(): void
    {
        app(Topics::class)->create($this->by, $this->workspace, 'The kernel', folderId: $this->first);
        app(Topics::class)->create($this->by, $this->workspace, 'Scheduling', folderId: $this->second);

        $this->actingAs($this->ada)->get(route('workspaces.folders.show', [$this->workspace, $this->first]))
            ->assertOk()->assertSee('<title>Lecture 1 + Lab 1 · Operating Systems', false)
            ->assertSeeInOrder(['Week 1: OS Structure | Processes &amp; Threads', 'Lecture 1 + Lab 1', '0 of 1 understood', 'Study this', 'Whole folder', 'Pick a topic', 'Quiz me', 'Test me'], false)
            ->assertSeeInOrder(['Topics', 'Files', 'Notes', 'Questions', 'Cards', 'The kernel'])
            ->assertDontSee('Scheduling');

        $this->folder()->call('studyModule')->assertDispatched('study-next', moduleId: $this->week1, folderId: $this->first)
            ->call('studyAsk', 'test')->assertDispatched('study-next', moduleId: $this->week1, ask: 'test', folderId: $this->first);
    }

    public function test_a_folder_outside_every_module_is_only_for_keeping_things(): void
    {
        $loose = app(Folders::class)->create($this->by, 'workspace', $this->workspace, 'Old handouts')->id;

        $this->actingAs($this->ada)->get(route('workspaces.folders.show', [$this->workspace, $loose]))
            ->assertOk()->assertSee('Old handouts')->assertDontSee('Study this')->assertDontSee('This folder')->assertSee('New note');
    }

    public function test_a_topic_added_on_a_folders_page_is_the_folders_and_so_are_the_topics_found_in_its_files(): void
    {
        $this->folder()->set('topicName', 'System calls')->call('addTopic')->assertSee('“System calls” is added.');
        $calls = collect(app(Topics::class)->list($this->by, $this->workspace))->firstWhere('name', 'System calls');
        $this->assertSame([$this->week1, $this->first], [$calls->moduleId, $calls->folderId]);

        $lecture = app(Files::class)->upload($this->by, 'folder', $this->first, $this->temp("Slides.\n"), 'Lecture 1 - OS Structure.txt');
        $other = app(Files::class)->upload($this->by, 'folder', $this->second, $this->temp("Slides.\n"), 'Lecture 2.txt');
        app(TopicSuggestions::class)->suggest($this->by, $this->week1, $lecture->id, ['Interrupts', 'Shells']);
        app(TopicSuggestions::class)->suggest($this->by, $this->week1, $other->id, ['Paging']);

        $this->folder()->assertSee('Main topics to review')->assertSee('Accept Interrupts')->assertSee('Accept Shells')->assertDontSee('Paging')
            ->call('addSuggested')->assertSee('2 topics added.');
        $this->assertSame($this->first, collect(app(Topics::class)->list($this->by, $this->workspace))->firstWhere('name', 'Shells')->folderId);
        $this->assertCount(1, app(TopicSuggestions::class)->list($this->by, $this->week1));
    }

    public function test_a_folders_questions_tab_lists_and_adds_its_questions(): void
    {
        app(Questions::class)->ask($this->by, $this->workspace, 'What is a system call?', folderId: $this->first);
        app(Questions::class)->ask($this->by, $this->workspace, 'What is a thread pool?', folderId: $this->second);

        $page = $this->folder('questions')->assertSee('What is a system call?')->assertDontSee('What is a thread pool?');
        $page->set('questionText', 'Why does the kernel need two modes?')->call('askQuestion')->assertSee('Question added.')->assertSee('Why does the kernel need two modes?');
        $this->assertCount(2, app(Questions::class)->list($this->by, $this->workspace, folderIds: [$this->first]));
        $this->folder('questions')->call('askQuestion')->assertHasErrors('questionText');
    }

    public function test_a_folders_cards_tab_counts_lists_and_reviews_its_cards(): void
    {
        app(Flashcards::class)->add($this->by, $this->workspace, null, 'What does a kernel do?', 'Runs the hardware.', folderId: $this->first);
        app(Flashcards::class)->add($this->by, $this->workspace, null, 'What is a thread?', 'A path of execution.', folderId: $this->second);

        $this->folder('cards')->assertSee('1 card, 1 due today')->assertSee('What does a kernel do?')->assertDontSee('What is a thread?')
            ->assertSee(route('workspaces.flashcards.review', [$this->workspace, 'folder' => $this->first]), false)
            ->assertSee(route('workspaces.show', [$this->workspace, 'flashcards', 'folder' => $this->first]), false);

        // The review round is the folder's cards only, and says so.
        $this->actingAs($this->ada)->get(route('workspaces.flashcards.review', [$this->workspace, 'folder' => $this->first]))
            ->assertOk()->assertSee('What does a kernel do?')->assertSee('Lecture 1 + Lab 1')->assertDontSee('What is a thread?');
        $this->as();
        $this->assertCount(1, Livewire::test(FlashcardReview::class, ['workspaceId' => $this->workspace, 'folderId' => $this->first])->get('queue'));

        // The Cards page, from the folder: only its cards, with the way back to the whole module.
        Livewire::withQueryParams(['folder' => $this->first])->test(Deck::class, ['workspaceId' => $this->workspace])
            ->assertSee('Only the cards in Lecture 1 + Lab 1')->assertSee('What does a kernel do?')->assertDontSee('What is a thread?')
            ->call('$set', 'folder', '')->assertSee('What is a thread?');
    }

    public function test_a_modules_topics_tab_shows_its_folders_each_with_study(): void
    {
        app(Topics::class)->create($this->by, $this->workspace, 'Week overview', $this->week1);
        app(Topics::class)->create($this->by, $this->workspace, 'The kernel', folderId: $this->first);
        $labs = app(Folders::class)->create($this->by, 'folder', $this->second, 'Labs')->id;
        app(Topics::class)->create($this->by, $this->workspace, 'Bash basics', folderId: $labs);

        $this->actingAs($this->ada)->get(route('workspaces.modules.show', [$this->workspace, $this->week1, 'tab' => 'topics']))
            ->assertOk()->assertSeeInOrder(['In the module', 'Week overview', 'Lecture 1 + Lab 1', '0 of 1 understood', 'The kernel', 'Lecture 2 + Lab 2', 'Bash basics'])
            ->assertSee('Study Lecture 1 + Lab 1')->assertSee('Study Lecture 2 + Lab 2');

        $this->actingAs($this->ada)->get(route('workspaces.modules.show', [$this->workspace, $this->week1]))
            ->assertOk()->assertSeeInOrder(['Folders', 'Files', 'Notes', 'Topics', 'Lecture 1 + Lab 1', 'Lecture 2 + Lab 2'])
            ->assertSee('Study Lecture 1 + Lab 1')->assertSee('Study Lecture 2 + Lab 2');

        $this->as();
        Livewire::test(Contents::class, ['workspaceId' => $this->workspace, 'view' => 'module', 'placeId' => $this->week1])
            ->call('studyFolder', $this->second)->assertDispatched('study-next', moduleId: $this->week1, folderId: $this->second);
    }

    public function test_a_modules_topics_tab_keeps_clean_ui_without_inline_files(): void
    {
        app(Files::class)->upload($this->by, 'folder', $this->first, $this->temp("Slides.\n"), 'Lecture 1 - OS Structure.txt');
        app(Notes::class)->create($this->by, 'folder', $this->first, 'Lab 01 Notes', []);

        $this->actingAs($this->ada)->get(route('workspaces.modules.show', [$this->workspace, $this->week1, 'tab' => 'topics']))
            ->assertOk()
            ->assertSee('Lecture 1 + Lab 1')
            ->assertSee('Study Lecture 1 + Lab 1')
            ->assertDontSee('Lecture 1 - OS Structure.txt')
            ->assertDontSee('Lab 01 Notes');

        // The files and notes remain neatly accessible in the folder's own tabs
        $this->folder('files')->assertSee('Lecture 1 - OS Structure.txt');
        $this->folder('notes')->assertSee('Lab 01 Notes');
    }

    public function test_study_now_on_a_folder_starts_a_session_there(): void
    {
        $this->as();

        Livewire::test(StudyTime::class, ['workspaceId' => $this->workspace])
            ->dispatch('study-next', moduleId: $this->week1, folderId: $this->first)
            ->assertRedirect();

        $session = app(Sessions::class)->current($this->by);
        $this->assertSame([$this->first, $this->week1, 'module'], [$session->folderId, $session->moduleId, $session->mode]);
    }

    public function test_the_session_in_a_folder_is_named_for_it_and_keeps_to_it(): void
    {
        app(Topics::class)->create($this->by, $this->workspace, 'The kernel', folderId: $this->first);
        app(Topics::class)->create($this->by, $this->workspace, 'Scheduling', folderId: $this->second);
        app(Files::class)->upload($this->by, 'folder', $this->first, $this->temp("Slides.\n"), 'Lecture 1 - OS Structure.txt');
        app(Notes::class)->create($this->by, 'folder', $this->second, 'Lecture 2 notes');
        $session = app(Sessions::class)->start($this->by, $this->workspace, folderId: $this->first);

        $this->actingAs($this->ada)->get(route('workspaces.sessions.show', [$this->workspace, $session->id]))
            ->assertOk()
            ->assertSeeInOrder(['Week 1: OS Structure | Processes &amp; Threads › Lecture 1 + Lab 1', 'Lecture 1 + Lab 1'], false)
            ->assertSee('Back to Lecture 1 + Lab 1')
            ->assertSee(route('workspaces.folders.show', [$this->workspace, $this->first]), false)
            ->assertSee('Topics in Lecture 1 + Lab 1')->assertSee('The kernel')->assertDontSee('Scheduling')
            ->assertSee('Lecture 1 - OS Structure.txt')->assertDontSee('Lecture 2 notes');

        $this->as();
        Livewire::test(StudySession::class, ['workspaceId' => $this->workspace, 'sessionId' => $session->id])
            ->call('editTopic')->set('topicChoice', 'new')->set('newTopic', 'Interrupts')->call('save');
        $this->assertSame($this->first, collect(app(Topics::class)->list($this->by, $this->workspace))->firstWhere('name', 'Interrupts')->folderId);
    }

    public function test_a_topic_made_before_the_folders_is_put_in_one_from_its_sheet(): void
    {
        $topic = app(Topics::class)->create($this->by, $this->workspace, 'The kernel', $this->week1)->id;
        $card = app(Flashcards::class)->add($this->by, $this->workspace, $topic, 'What does a kernel do?', 'Runs the hardware.');
        $labs = app(Folders::class)->create($this->by, 'folder', $this->first, 'Labs')->id;
        $loose = app(Folders::class)->create($this->by, 'workspace', $this->workspace, 'Old handouts')->id;
        $this->as();

        // The sheet offers the module itself and each of its folders, by name and inside one another; not a loose folder.
        $sheet = Livewire::test(TopicSheet::class, ['workspaceId' => $this->workspace])->dispatch('topic-sheet-open', topicId: $topic)
            ->assertSet('place', "module:{$this->week1}")
            ->assertSeeHtml('<optgroup label="Week 1: OS Structure | Processes &amp; Threads">')
            ->assertSeeInOrder(['Module or folder', 'No module', 'In the module', 'Lecture 1 + Lab 1', 'Labs', 'Lecture 2 + Lab 2'])
            ->assertSee('Lecture 1 + Lab 1 › Labs')
            ->assertDontSee('Old handouts');

        $module = Livewire::withQueryParams(['tab' => 'topics'])->test(Contents::class, ['workspaceId' => $this->workspace, 'view' => 'module', 'placeId' => $this->week1])
            ->assertSeeInOrder(['In the module', 'The kernel', 'Lecture 1 + Lab 1', 'No topics yet']);
        $sheet->set('place', "folder:{$this->first}")->call('move')->assertHasNoErrors()->assertDispatched('topics-changed');
        // The module's page, open behind the sheet, draws the topic in its folder.
        $module->dispatch('topics-changed')->assertDontSee('In the module')->assertSeeInOrder(['Lecture 1 + Lab 1', '0 of 1 understood', 'The kernel']);
        $kernel = app(Topics::class)->find($this->by, $topic);
        $this->assertSame([$this->week1, $this->first], [$kernel->moduleId, $kernel->folderId]);
        $this->assertSame($this->first, app(Flashcards::class)->find($this->by, $card)->folderId);

        // Opened again it says where it is; back to the module itself, out of the folder.
        $sheet->dispatch('topic-sheet-open', topicId: $topic)->assertSet('place', "folder:{$this->first}")
            ->set('place', "module:{$this->week1}")->call('move');
        $this->assertNull(app(Topics::class)->find($this->by, $topic)->folderId);

        // A folder outside every module is not a place for a topic, and neither is one that is gone.
        $sheet->dispatch('topic-sheet-open', topicId: $topic)->set('place', "folder:{$loose}")->call('move')->assertHasErrors('place');
        $sheet->set('place', 'folder:01a0aaaa-0000-7000-8000-000000000000')->call('move')->assertHasErrors('place');
        $sheet->set('place', "topic:{$topic}")->call('move')->assertHasErrors('place');
        $this->assertSame([$this->week1, null], [app(Topics::class)->find($this->by, $topic)->moduleId, app(Topics::class)->find($this->by, $topic)->folderId]);
    }

    public function test_moving_a_topic_on_progress_keeps_its_folder_in_the_same_module(): void
    {
        $kernel = app(Topics::class)->create($this->by, $this->workspace, 'The kernel', folderId: $this->first)->id;
        $week2 = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 2: Concurrency'])->id;
        $this->as();

        $page = Livewire::test(Progress::class, ['workspaceId' => $this->workspace]);
        $page->call('moveTopic', $kernel)->call('save');
        $this->assertSame($this->first, app(Topics::class)->find($this->by, $kernel)->folderId);
        $page->call('moveTopic', $kernel)->set('moduleId', $week2)->call('save');
        $this->assertSame([$week2, null], [app(Topics::class)->find($this->by, $kernel)->moduleId, app(Topics::class)->find($this->by, $kernel)->folderId]);
    }

    public function test_progress_shows_a_modules_topics_under_their_folders(): void
    {
        app(Topics::class)->create($this->by, $this->workspace, 'Scheduling', folderId: $this->second);
        app(Topics::class)->create($this->by, $this->workspace, 'The kernel', folderId: $this->first);
        app(Topics::class)->create($this->by, $this->workspace, 'Week overview', $this->week1);

        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->workspace, 'progress']))
            ->assertOk()->assertSeeInOrder(['Week overview', 'Lecture 1 + Lab 1', 'The kernel', 'Lecture 2 + Lab 2', 'Scheduling']);
    }

    public function test_find_the_main_topics_reads_all_of_the_parts_files_and_each_comes_with_why(): void
    {
        $engine = new Fake;
        $this->app->instance(Engine::class, $engine);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
        $slides = app(Files::class)->upload($this->by, 'folder', $this->first, $this->temp("Process lifecycle, states and scheduling.\n"), 'Processes.txt');
        $handbook = app(Files::class)->upload($this->by, 'folder', $this->first, $this->temp("Office hours and deadlines.\n"), 'Handbook.txt');
        foreach ([[$slides, 'Process states and scheduling.', ['Process states', 'Scheduling']], [$handbook, 'Office hours.', ['Office hours']]] as [$file, $summary, $topics]) {
            app(FileDigests::class)->keep($this->by, $file->id, ['summary' => $summary, 'outline' => [], 'topics' => $topics, 'language' => 'English'], 3, 100, 'fake/quick');
        }
        $engine->will(Fake::says(json_encode(['files' => [['file' => 'F1', 'use' => 'core', 'why' => 'The lecture.'], ['file' => 'F2', 'use' => 'skip', 'why' => 'Course admin.']], 'topics' => [
            ['name' => 'Process lifecycle', 'why' => 'Every later topic in the week builds on how a process moves between states.', 'covers' => ['Process states', 'Transitions'], 'files' => ['F1'], 'pages' => '1–3'],
        ]])));

        $dash = $this->folder('dashboard')->assertSee('No topics yet')->assertDontSee('Main topics to review')
            ->call('chooseTopics')
            ->assertSee('Main topics to review')->assertSee('1 topic from 1 of 2 files, in the order to study them')
            ->assertSee('Process lifecycle')->assertSee('Every later topic in the week builds on')
            ->assertSee('Transitions')->assertSee('Processes.txt · p. 1–3')
            ->assertSee('What the AI read: 1 used, 1 left out')->assertSee('Course admin.')
            ->assertDontSee('Generate Study Map')->assertDontSee('Foundational Principles');

        // Accepted, it is the folder's topic, tracked from now on.
        $dash->call('addSuggested')->assertSee('1 topic added.')->assertDontSee('Main topics to review');
        $topic = collect(app(Topics::class)->list($this->by, $this->workspace))->firstWhere('name', 'Process lifecycle');
        $this->assertSame([$this->first, 'not_started'], [$topic->folderId, $topic->shown()]);
    }

    public function test_each_topic_shows_the_ais_note_and_the_student_can_have_the_ai_choose_again(): void
    {
        $engine = new Fake;
        $this->app->instance(Engine::class, $engine);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
        $slides = app(Files::class)->upload($this->by, 'folder', $this->first, $this->temp("Graphs, BFS and DFS.\n"), 'Graphs.txt');
        app(FileDigests::class)->keep($this->by, $slides->id, ['summary' => 'Graphs, BFS and DFS.', 'outline' => [], 'topics' => ['Graphs', 'BFS', 'DFS'], 'language' => 'English'], 3, 100, 'fake/quick');
        $engine->will(Fake::says(json_encode(['files' => [['file' => 'F1', 'use' => 'core', 'why' => 'The lecture.']], 'topics' => [
            ['name' => 'Graphs', 'why' => 'Many problems are graphs.', 'note' => 'All 30 pages build on it; folded in BFS and DFS.', 'files' => ['F1']],
        ]])));
        $dash = $this->folder('dashboard')->call('chooseTopics')
            ->assertSee('Why the AI chose Graphs')->assertSee('Why the AI chose it')->assertSee('All 30 pages build on it; folded in BFS and DFS.')
            ->assertSee('Choose again')->assertSee('Ask the AI to rethink it');

        // Rethink one: the box opens with its name, the student says what to change, and the AI chooses again.
        $graphs = app(TopicSuggestions::class)->list($this->by, $this->week1)[0];
        $dash->call('openChooseAgain', $graphs->id)->assertSet('choosingAgain', true)->assertSet('againNote', 'About “Graphs”: ')
            ->assertSee('What should the AI change?');
        $engine->will(Fake::says(json_encode(['files' => [], 'topics' => [
            ['name' => 'Breadth-first search', 'why' => 'Shortest paths.', 'note' => 'Split from Graphs, as asked.', 'files' => ['F1']],
            ['name' => 'Depth-first search', 'why' => 'Cycles.', 'note' => 'Split from Graphs, as asked.', 'files' => ['F1']],
        ]])));
        $dash->set('againNote', 'About “Graphs”: too broad, split it into BFS and DFS.')->call('chooseAgain')
            ->assertSet('choosingAgain', false)->assertSet('againNote', '')
            ->assertSee('Breadth-first search')->assertSee('Depth-first search')->assertDontSee('Why the AI chose Graphs');
        $this->assertStringContainsString('too broad, split it into BFS and DFS.', $engine->requests[1]->messages[0]['content']);

        // Cancel closes the box with nothing sent.
        $dash->call('openChooseAgain')->assertSet('choosingAgain', true)->call('cancelChooseAgain')->assertSet('choosingAgain', false);
        $this->assertCount(2, $engine->requests);
    }

    public function test_the_student_ticks_which_files_the_ai_reads_for_topics_and_that_is_remembered(): void
    {
        $engine = new Fake;
        $this->app->instance(Engine::class, $engine);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
        $slides = app(Files::class)->upload($this->by, 'folder', $this->first, $this->temp("Process states.\n"), 'Slides.txt');
        $revision = app(Files::class)->upload($this->by, 'folder', $this->first, $this->temp("Last year's revision.\n"), 'Revision.txt');
        foreach ([$slides, $revision] as $file) {
            app(FileDigests::class)->keep($this->by, $file->id, ['summary' => "About {$file->name}.", 'outline' => [], 'topics' => ['Process states'], 'language' => 'English'], 2, 50, 'fake/quick');
        }

        // Finding the topics opens the form first: every file ticked, each saying whether it is read.
        $page = $this->folder('dashboard')->call('openChooseAgain')
            ->assertSee('Find the main topics')->assertSee('Files the AI reads')->assertSee('Slides.txt')->assertSee('Revision.txt')->assertSee('Read')
            ->assertSee('Anything the AI should know?');
        $this->assertEqualsCanonicalizing([$slides->id, $revision->id], $page->get('aiFiles'));

        // None ticked: refused.
        $page->set('aiFiles', [])->call('chooseAgain')->assertHasErrors('aiFiles')->assertSee('Tick at least one file for the AI to read.');
        $this->assertSame([], $engine->requests);

        // Only the slides: the revision file is not given to the AI, now or the next time.
        $engine->will(Fake::says(json_encode(['files' => [['file' => 'F1', 'use' => 'core', 'why' => '']], 'topics' => [['name' => 'Process states', 'why' => 'x', 'files' => ['F1']]]])));
        $page->set('aiFiles', [$slides->id])->call('chooseAgain')->assertHasNoErrors()->assertSet('choosingAgain', false)->assertSee('Process states');
        $sent = $engine->requests[0]->messages[0]['content'];
        $this->assertStringContainsString('F1: Slides.txt', $sent);
        $this->assertStringNotContainsString('Revision.txt', $sent);
        $this->assertSame([$revision->id], ChooseTopics::leftOut($this->by, $this->week1));
        $this->folder('dashboard')->call('openChooseAgain')->assertSet('aiFiles', [$slides->id]);
    }

    public function test_a_topics_sheet_offers_its_statuses(): void
    {
        $topic = app(Topics::class)->create($this->by, $this->workspace, 'Process lifecycle', $this->week1, $this->first);
        $this->as();

        $sheet = Livewire::test(TopicSheet::class, ['workspaceId' => $this->workspace])
            ->dispatch('topic-sheet-open', topicId: $topic->id)
            ->assertSee('Studying')
            ->assertSee('Understood')
            ->assertSee('Not understood');

        $sheet->call('report', 'covered');
        $this->assertSame('covered', app(Topics::class)->find($this->by, $topic->id)->status);

        $sheet->call('report', 'confused');
        $this->assertSame('confused', app(Topics::class)->find($this->by, $topic->id)->status);
    }
}
