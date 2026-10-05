<?php

namespace Tests\Feature\Engine;

use App\Engine\Context\Stack;
use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Engine\Toolbox;
use App\Engine\Tools\Context;
use App\Livewire\Workspaces\TutorChat;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Files;
use App\Study\Flashcards;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use App\Study\WriteBack;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * The tutor in a folder's session (docs/specs/vistud-2-blueprint.md, Phase 9): it is told where it is and given the
 * folder, not the whole module; its tools default to the folder; what it saves and writes goes in the folder.
 */
class FolderSessionTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    private string $week1;

    private string $week2;

    private string $first;

    private string $second;

    private SessionDetails $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems'])->id;
        $this->week1 = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 1: OS Structure | Processes & Threads', 'starts_on' => '2026-09-21', 'ends_on' => '2026-09-27'])->id;
        $this->week2 = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 2: Concurrency'])->id;
        $folders = app(Folders::class);
        $this->first = $folders->create($this->by, 'module', $this->week1, 'Lecture 1 + Lab 1')->id;
        $this->second = $folders->create($this->by, 'module', $this->week1, 'Lecture 2 + Lab 2')->id;
        $files = app(Files::class);
        $files->upload($this->by, 'folder', $this->first, $this->temp("Operating system structure.\n"), 'Lecture 1 - OS Structure.txt');
        $files->upload($this->by, 'folder', $this->first, $this->temp("Command line basics.\n"), 'Lab 01 - Command Line Basics.txt');
        $files->upload($this->by, 'folder', $this->second, $this->temp("Processes and threads.\n"), 'Lecture 2 - Processes and Threads.txt');
        $topics = app(Topics::class);
        $kernel = $topics->create($this->by, $this->workspace, 'The kernel', folderId: $this->first);
        $topics->report($this->by, $kernel->id, 'understood');
        $topics->create($this->by, $this->workspace, 'Threads', folderId: $this->second);
        $topics->create($this->by, $this->workspace, 'Week 1 overview', $this->week1);
        $this->session = app(Sessions::class)->start($this->by, $this->workspace, folderId: $this->first);
    }

    private function context(?SessionDetails $session = null): Context
    {
        $session ??= $this->session;

        return new Context($this->workspace, $session->moduleId, $session->id, 'UTC', folderId: $session->folderId);
    }

    private function use(string $tool, array $input = [], ?Context $context = null): string
    {
        return app(Toolbox::class)->run($this->by, $context ?? $this->context(), $tool, $input);
    }

    public function test_the_tutor_is_told_where_it_is_and_given_the_folder_not_the_whole_module(): void
    {
        $system = app(Stack::class)->build($this->by, $this->session, null, true)->system;

        $this->assertStringContainsString("## Where you are\nOperating Systems › Week 1: OS Structure | Processes & Threads (21 Sep – 27 Sep) › Lecture 1 + Lab 1", $system);
        $this->assertStringContainsString('This session is in the folder Lecture 1 + Lab 1: teach from its files and keep to its topics. What you save (notes, cards, questions, key points, new topics) goes in it.', $system);
        $this->assertStringContainsString("The module's other folders are for other sessions: Lecture 2 + Lab 2.", $system);
        $this->assertStringContainsString("Topics in this folder, with what the student says of each:\n- The kernel: understood", $system);
        $this->assertStringContainsString('- Lecture 1 - OS Structure.txt (not read yet)', $system);
        $this->assertStringContainsString('- Lab 01 - Command Line Basics.txt (not read yet)', $system);
        $this->assertStringContainsString("Mode: whole folder. Go through the folder's material in order, topic by topic.", $system);
        // The module's other topics and files are another session's.
        foreach (['- Threads:', 'Week 1 overview', 'Lecture 2 - Processes', '## The module:'] as $elsewhere) {
            $this->assertStringNotContainsString($elsewhere, $system);
        }
        $this->assertSame(1, DB::table('folder_briefs')->where('folder_id', $this->first)->count());
    }

    public function test_a_session_on_the_whole_module_is_told_about_the_module_as_before(): void
    {
        app(Sessions::class)->end($this->by, $this->session->id);
        $module = app(Sessions::class)->start($this->by, $this->workspace, moduleId: $this->week1);

        $system = app(Stack::class)->build($this->by, $module, null, true)->system;

        $this->assertStringContainsString('## The module: Week 1: OS Structure | Processes & Threads', $system);
        $this->assertStringNotContainsString('## Where you are', $system);
        foreach (['- The kernel: understood', '- Threads: not started', '- Week 1 overview: not started', 'Lecture 2 - Processes and Threads.txt'] as $part) {
            $this->assertStringContainsString($part, $system);
        }
        $this->assertStringContainsString("Mode: whole module. Go through the module's material in order, topic by topic.", $system);
    }

    public function test_the_look_ups_default_to_the_folder_and_can_ask_for_more(): void
    {
        $files = json_decode($this->use('module_files'), true);
        $this->assertSame(['Lab 01 - Command Line Basics.txt', 'Lecture 1 - OS Structure.txt'], collect($files)->pluck('file')->sort()->values()->all());
        $this->assertCount(3, json_decode($this->use('module_files', ['module' => 'Week 1']), true));

        $this->assertSame(['The kernel'], array_column(json_decode($this->use('topics'), true), 'topic'));
        $everywhere = json_decode($this->use('topics', ['everywhere' => true]), true);
        $this->assertCount(3, $everywhere);
        $this->assertSame('Lecture 2 + Lab 2', collect($everywhere)->firstWhere('topic', 'Threads')['folder']);
    }

    public function test_a_file_named_like_one_elsewhere_is_the_folders_own(): void
    {
        app(Files::class)->upload($this->by, 'module', $this->week2, $this->temp("Another lab.\n"), 'Lab 01 - Command Line Basics (old).txt');

        $this->assertStringContainsString('Command line basics.', $this->use('read_file', ['file' => 'Lab 01']));
        // Outside the folder's session, the same words fit two files.
        $plain = new Context($this->workspace, $this->week1, null, 'UTC');
        $this->assertStringContainsString('Several files match', app(Toolbox::class)->run($this->by, $plain, 'read_file', ['file' => 'Lab 01']));
    }

    public function test_topics_cards_and_questions_the_tutor_makes_go_in_the_folder(): void
    {
        $this->assertStringContainsString('new in the course in Lecture 1 + Lab 1, in Week 1', $this->use('set_topic', ['topic' => 'System calls']));
        $calls = collect(app(Topics::class)->list($this->by, $this->workspace))->firstWhere('name', 'System calls');
        $this->assertSame([$this->week1, $this->first], [$calls->moduleId, $calls->folderId]);

        $this->use('add_topics', ['topics' => [['name' => 'Shells'], ['name' => 'Semaphores', 'module' => 'Week 2']]]);
        $topics = collect(app(Topics::class)->list($this->by, $this->workspace))->keyBy('name');
        $this->assertSame([$this->week1, $this->first], [$topics['Shells']->moduleId, $topics['Shells']->folderId]);
        $this->assertSame([$this->week2, null], [$topics['Semaphores']->moduleId, $topics['Semaphores']->folderId]);

        $this->use('make_flashcards', ['cards' => [['front' => 'What is a system call?', 'back' => 'A request to the kernel.']]]);
        $this->use('add_questions', ['questions' => [['text' => 'How does the shell find a program?', 'topic' => 'Shells']]]);
        $card = app(Flashcards::class)->list($this->by, $this->workspace)[0];
        $this->assertSame([$calls->id, $this->first], [$card->topicId, $card->folderId]);
        $question = app(Questions::class)->list($this->by, $this->workspace)[0];
        $this->assertSame([$this->first, $this->week1], [$question->folderId, $question->moduleId]);
    }

    public function test_a_card_with_no_topic_says_it_went_in_the_folder(): void
    {
        $said = $this->use('make_flashcards', ['cards' => [['front' => 'What is an OS?', 'back' => 'Software that runs the hardware.']]]);

        $this->assertStringContainsString('Lecture 1 + Lab 1 (no topic)', $said);
        $this->assertSame($this->first, app(Flashcards::class)->list($this->by, $this->workspace)[0]->folderId);
    }

    public function test_the_tutors_notes_and_the_session_note_are_written_in_the_folder(): void
    {
        $this->assertStringContainsString('Started the note "Study notes · Lecture 1 + Lab 1 · Mon 5 Oct"', $this->use('write_note', ['text' => '## The kernel\n\nRuns in privileged mode.']));
        $this->use('write_note', ['text' => 'A new one.', 'title' => 'Kernel summary']);
        $notes = collect(app(Notes::class)->list($this->by, $this->workspace))->keyBy(fn ($n) => $n->displayTitle());
        $this->assertSame($this->first, $notes['Study notes · Lecture 1 + Lab 1 · Mon 5 Oct']->folderId);
        $this->assertSame($this->first, $notes['Kernel summary']->folderId);

        // The write-back's session note, and a topic it makes, go there too.
        $writeBack = app(WriteBack::class);
        $writeBack->apply($this->by, $this->session->id, [['kind' => 'flashcard', 'include' => true, 'front' => 'Q', 'back' => 'A', 'topic_id' => 'new', 'topic_name' => 'Interrupts']]);
        $interrupts = collect(app(Topics::class)->list($this->by, $this->workspace))->firstWhere('name', 'Interrupts');
        $this->assertSame($this->first, $interrupts->folderId);
        $sessionNote = app(Notes::class)->find($this->by, (string) app(Sessions::class)->noteId($this->by, $this->session->id));
        $this->assertSame($this->first, $sessionNote->folderId);
    }

    public function test_the_chat_offers_the_folders_material_its_quiz_and_uploads_into_it(): void
    {
        $this->app->instance(Engine::class, new Fake);
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        app(Notes::class)->create($this->by, 'folder', $this->second, 'Lecture 2 notes');

        $chat = Livewire::test(TutorChat::class, ['workspaceId' => $this->workspace, 'sessionId' => $this->session->id]);

        $chat->assertSee('Lecture 1 - OS Structure.txt')->assertSee('Lab 01 - Command Line Basics.txt')
            ->assertDontSee('Lecture 2 - Processes and Threads.txt')->assertDontSee('Lecture 2 notes')
            ->assertSee('Quiz me on the folder Lecture 1 + Lab 1.');
        $this->assertSame(['folder', $this->first], [$chat->viewData('upload')['type'], $chat->viewData('upload')['id']]);
    }
}
