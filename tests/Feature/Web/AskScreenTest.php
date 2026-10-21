<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Helper;
use App\Engine\Settings;
use App\Livewire\Workspaces\Ask;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Study\Flashcards;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Ask: a short talk with the quick helper about the course, kept nowhere, with a way to the tutor (docs/specs/vistud-2-blueprint.md §3.6.1). */
class AskScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

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
        $module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 3'])->id;
        $topic = app(Topics::class)->create($this->by, $this->workspace, 'Deadlocks', $module)->id;
        app(Topics::class)->report($this->by, $topic, 'confused');
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->ownAiKey($this->by);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/plain', 'helper_model' => 'fake/quick', 'consent' => true]);
    }

    private function ask()
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(Ask::class, ['workspaceId' => $this->workspace]);
    }

    public function test_every_course_page_has_the_button_in_the_top_bar_and_other_pages_do_not(): void
    {
        $this->actingAs($this->ada);

        $this->get(route('workspaces.show', $this->workspace))->assertOk()->assertSeeLivewire(Ask::class)->assertSee('id="ask-sheet"', false)->assertSee('Need teaching?')->assertSee('Start a session');
        $this->get(route('workspaces.show', [$this->workspace, 'progress']))->assertOk()->assertSeeLivewire(Ask::class);
        $this->get(route('home'))->assertOk()->assertDontSeeLivewire(Ask::class);
    }

    public function test_a_question_is_answered_by_the_helper_from_the_course_and_nothing_is_stored(): void
    {
        $this->engine->will(
            Fake::calls('topics', [], 'call_1'),
            Fake::says('Deadlocks is the one you find confusing.', 2_000, 'fake/quick'),
        );

        $this->ask()->set('text', 'What do I find hard?')->call('send')
            ->assertSet('text', '')->assertSee('What do I find hard?')->assertSee('Deadlocks is the one you find confusing.')
            ->assertSet('talk', [['from' => 'you', 'text' => 'What do I find hard?'], ['from' => 'helper', 'text' => 'Deadlocks is the one you find confusing.']]);

        // It ran as the helper, in this course, with read-only look-ups; the run is recorded, the words are not.
        [$first] = $this->engine->requests;
        $this->assertSame(Helper::TOOLS, array_map(fn (array $t) => $t['function']['name'], $first->tools));
        $this->assertStringContainsString('## The course: Operating Systems', $first->system);
        $job = LearnerTables::query(LearnerScope::of($this->by), 'engine_jobs')->firstOrFail();
        $this->assertSame(['helper', 'quick', $this->workspace, 'done'], [$job->role, $job->kind, $job->workspace_id, $job->status]);
        $this->assertSame([], app(Flashcards::class)->list($this->by, $this->workspace));
    }

    public function test_asking_in_a_folder_passes_the_folder_context_to_the_helper(): void
    {
        $folder = app(Folders::class)->create($this->by, 'workspace', $this->workspace, 'Processes')->id;

        $this->engine->will(Fake::says('You are in the Processes folder.', 1_000, 'fake/quick'));

        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        Livewire::test(Ask::class, ['workspaceId' => $this->workspace, 'folderId' => $folder])
            ->set('text', 'Which folder are we in?')
            ->call('send')
            ->assertSee('You are in the Processes folder.');

        [$first] = $this->engine->requests;
        $this->assertStringContainsString('The student is looking at the folder Processes.', $first->system);
        $this->assertStringContainsString('Operating Systems › Processes', $first->system);
    }

    public function test_teaching_from_a_folder_starts_a_session_in_that_folder(): void
    {
        $folder = app(Folders::class)->create($this->by, 'workspace', $this->workspace, 'Processes')->id;

        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        Livewire::test(Ask::class, ['workspaceId' => $this->workspace, 'folderId' => $folder])
            ->call('teach')
            ->assertDispatched('session-changed')
            ->assertRedirect();

        $session = app(Sessions::class)->current($this->by);
        $this->assertNotNull($session);
        $this->assertSame($this->workspace, $session->workspaceId);
        $this->assertSame($folder, $session->folderId);
    }

    public function test_a_folder_page_mounts_ask_with_the_folder(): void
    {
        $folder = app(Folders::class)->create($this->by, 'workspace', $this->workspace, 'Processes')->id;

        $this->actingAs($this->ada);

        $this->get(route('workspaces.folders.show', [$this->workspace, $folder]))
            ->assertOk()
            ->assertSeeLivewire(Ask::class);
    }

    public function test_the_talk_so_far_goes_with_the_next_question(): void
    {
        $this->engine->will(Fake::says('Deadlocks, in Week 3.', 1_000, 'fake/quick'), Fake::says('Four conditions.', 1_000, 'fake/quick'));

        $this->ask()->set('text', 'What do I find hard?')->call('send')->set('text', 'Why is it hard?')->call('send')
            ->assertSee('Four conditions.');

        $message = $this->engine->requests[1]->messages[0]['content'];
        $this->assertStringContainsString('Task: Why is it hard?', $message);
        $this->assertStringContainsString("Student: What do I find hard?\nYou: Deadlocks, in Week 3.", $message);
    }

    public function test_an_empty_or_too_long_question_is_not_sent_and_a_failure_is_said_in_the_talk(): void
    {
        $this->ask()->call('send')->assertHasErrors('text')->set('text', str_repeat('a', Ask::MAX_ASK + 1))->call('send')->assertHasErrors('text');
        $this->assertSame([], $this->engine->requests);

        $this->engine->will(Fake::says("  \n"));
        $this->ask()->set('text', 'Hello?')->call('send')->assertSee('had nothing to say')->assertSet('talk.1.from', 'problem');

        // Not set up: the way on is in the words.
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/plain', 'helper_model' => 'fake/quick', 'consent' => false]);
        $this->ask()->set('text', 'Hello?')->call('send')->assertSet('talk.1.from', 'problem');
        $this->assertCount(1, $this->engine->requests, 'no call without consent');
    }

    public function test_only_the_last_exchanges_are_kept_and_start_over_clears_the_talk(): void
    {
        $page = $this->ask();
        foreach (range(1, 8) as $n) {
            $this->engine->will(Fake::says("Answer {$n}", 100, 'fake/quick'));
            $page->set('text', "Question {$n}")->call('send');
        }
        $page->assertCount('talk', Ask::KEEP)->assertDontSee('Question 1')->assertSee('Question 8');

        $page->call('clear')->assertSet('talk', [])->assertSee('Ask about your course');
    }

    public function test_the_line_at_the_bottom_starts_a_session_or_goes_back_to_the_open_one(): void
    {
        $this->ask()->call('teach')->assertDispatched('session-changed')->assertRedirect();
        $session = app(Sessions::class)->current($this->by);
        $this->assertNotNull($session);
        $this->assertSame($this->workspace, $session->workspaceId);

        $this->ask()->call('teach')->assertRedirect(route('workspaces.sessions.show', [$this->workspace, $session->id]));
        $this->assertCount(1, app(Sessions::class)->list($this->by, $this->workspace));
    }

    public function test_the_browser_cannot_change_the_course_or_the_talk(): void
    {
        $this->assertThrows(fn () => $this->ask()->set('workspaceId', 'other'), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $this->ask()->set('talk', [['from' => 'helper', 'text' => 'Forged']]), CannotUpdateLockedPropertyException::class);
    }

    public function test_another_students_course_cannot_be_asked_about(): void
    {
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private'])->id;
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        Livewire::test(Ask::class, ['workspaceId' => $theirs])->set('text', 'What is in it?')->call('send')->assertSet('talk.1.from', 'problem');
        $this->assertSame([], $this->engine->requests);
    }

    private function fillUpload($page, string $content, int $index = 0): void
    {
        file_put_contents($page->get('uploads')[$index]->getRealPath(), $content);
    }

    private function fillImage($page, int $index = 0): void
    {
        $png = imagecreatetruecolor(300, 200);
        ob_start();
        imagepng($png);
        $this->fillUpload($page, (string) ob_get_clean(), $index);
    }

    public function test_a_picture_can_be_attached_to_a_question(): void
    {
        app(Settings::class)->set($this->by, ['helper_model' => 'fake/tutor']);
        $this->engine->will(Fake::says('I see the memory hierarchy chart.', 1_000, 'fake/tutor'));

        $image = UploadedFile::fake()->image('chart.png', 400, 300);

        $test = $this->ask()
            ->set('uploads', [$image])
            ->assertSeeHtml('wire:click="removeUpload(0)"')
            ->assertSee('Attached to your question');
        $this->fillImage($test);

        $test->set('text', 'Explain this chart')
            ->call('send');

        $test->assertSet('uploads', [])
            ->assertSet('text', '')
            ->assertSee('Explain this chart')
            ->assertSee('I see the memory hierarchy chart.')
            ->assertSet('talk.0.attachments.0.kind', 'image');
        $this->assertNotEmpty($test->get('talk.0.attachments.0.name'));

        $this->assertCount(1, $this->engine->requests);
        $content = $this->engine->requests[0]->messages[0]['content'];
        $this->assertIsArray($content);
        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('image_url', $content[1]['type']);
    }

    public function test_a_document_file_can_be_attached_to_a_question(): void
    {
        $this->engine->will(Fake::says('Deadlock requires mutual exclusion, hold and wait, no preemption, circular wait.', 1_000, 'fake/quick'));

        $doc = UploadedFile::fake()->createWithContent('coffman.txt', 'The Coffman conditions for deadlock: mutual exclusion, hold and wait, no preemption, circular wait.');

        $test = $this->ask()
            ->set('uploads', [$doc]);
        $this->fillUpload($test, 'The Coffman conditions for deadlock: mutual exclusion, hold and wait, no preemption, circular wait.');

        $test->set('text', 'What are the conditions?')
            ->call('send');

        $test->assertSet('uploads', [])
            ->assertSee('What are the conditions?')
            ->assertSee('Deadlock requires mutual exclusion')
            ->assertSet('talk.0.attachments.0.kind', 'file');
        $this->assertNotEmpty($test->get('talk.0.attachments.0.name'));

        $this->assertCount(1, $this->engine->requests);
        $content = $this->engine->requests[0]->messages[0]['content'];
        $this->assertIsString($content);
        $this->assertStringContainsString('[Attached document:', $content);
        $this->assertStringContainsString('The Coffman conditions for deadlock', $content);
    }

    public function test_attachments_can_be_removed_and_cleared(): void
    {
        $doc = UploadedFile::fake()->createWithContent('notes.txt', 'Some notes');
        $img = UploadedFile::fake()->image('photo.png');

        $test = $this->ask()
            ->set('uploads', [$doc, $img])
            ->assertCount('uploads', 2)
            ->call('removeUpload', 0)
            ->assertCount('uploads', 1);

        $test->call('clear')
            ->assertSet('uploads', [])
            ->assertSet('talk', []);
    }

    public function test_invalid_attachment_extensions_are_rejected(): void
    {
        $bad = UploadedFile::fake()->create('script.exe', 100);

        $this->ask()
            ->set('uploads', [$bad])
            ->assertHasErrors('uploads')
            ->assertSee('Choose a picture, PDF, Word or text file.')
            ->assertSet('uploads', []);
    }
}
