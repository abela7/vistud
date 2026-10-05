<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Livewire\Workspaces\Contents;
use App\Livewire\Workspaces\Deck;
use App\Livewire\Workspaces\FlashcardReview;
use App\Livewire\Workspaces\Progress;
use App\Livewire\Workspaces\StudySession;
use App\Livewire\Workspaces\StudyTime;
use App\Livewire\Workspaces\TopicSheet;
use App\Models\User;
use App\Platform\Access\Principal;
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

        $this->folder()->assertSee('2 new topics found')->assertSee('Interrupts, Shells')->assertDontSee('Paging')
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

        $this->actingAs($this->ada)->get(route('workspaces.modules.show', [$this->workspace, $this->week1]))
            ->assertOk()->assertSeeInOrder(['In the module', 'Week overview', 'Lecture 1 + Lab 1', '0 of 1 understood', 'The kernel', 'Lecture 2 + Lab 2', 'Bash basics'])
            ->assertSee('Study Lecture 1 + Lab 1')->assertSee('Study Lecture 2 + Lab 2');

        $this->as();
        Livewire::test(Contents::class, ['workspaceId' => $this->workspace, 'view' => 'module', 'placeId' => $this->week1])
            ->call('studyFolder', $this->second)->assertDispatched('study-next', moduleId: $this->week1, folderId: $this->second);
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

        $module = Livewire::test(Contents::class, ['workspaceId' => $this->workspace, 'view' => 'module', 'placeId' => $this->week1])
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
}
