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
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
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
        $this->ask()->call('send')->assertHasErrors('text')->set('text', str_repeat('a', 501))->call('send')->assertHasErrors('text');
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
}
