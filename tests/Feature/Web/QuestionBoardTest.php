<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\QuestionBoard;
use App\Livewire\Workspaces\StudySession;
use App\Models\User;
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\QuestionDetails;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Questions on a module's page, in a study session and in Progress (docs/specs/study-memory.md §3). */
class QuestionBoardTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $databases;

    private ModuleDetails $week1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $this->week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);
    }

    public function test_a_question_is_written_in_a_line_and_marked_from_its_card(): void
    {
        $board = $this->board(['moduleId' => $this->week1->id, 'page' => true])
            ->call('add')->assertHasErrors('text')
            ->set('text', 'Why is a primary key never empty?')->call('add')
            ->assertSet('text', '')->assertDispatched('questions-changed')
            ->assertSeeInOrder(['New question', 'All', '1', 'Pending', '1', 'Why is a primary key never empty?']);
        $question = $this->questions()[0];
        $this->assertSame([$this->week1->id, 'pending'], [$question->moduleId, $question->status]);

        // Each card is a link to the question's own page, and its menu has the way to answer it there.
        $page = route('workspaces.questions.show', [$this->databases->id, $question->id]);
        $board->assertSee('href="'.$page.'"', false)->assertSee($page.'?status=answered', false);

        // One click from the card's menu.
        $board->call('mark', $question->id, 'stuck')->assertSeeInOrder(['Stuck', 'Why is a primary key never empty?']);
        $this->assertSame('stuck', $this->questions()[0]->status);

        $board->call('show', 'pending')->assertSee('None pending.')->call('show', 'stuck')->assertSee('Why is a primary key never empty?');
    }

    public function test_in_a_session_questions_go_to_its_module_and_the_session(): void
    {
        $by = $this->principal($this->ada);
        $session = app(Sessions::class)->start($by, $this->databases->id, null, $this->week1->id);
        app(Questions::class)->ask($by, $this->databases->id, 'Asked earlier, in the module', null, $this->week1->id, 'an-earlier-session');

        $this->actingAs($this->ada)->get(route('workspaces.sessions.show', [$this->databases->id, $session->id]))
            ->assertOk()->assertDontSee('Asked earlier, in the module')->assertDontSee("What don't you get? Write it down…", false);

        // A question is asked from the box's + menu: it goes to the module and to the session, and is on the module's Questions tab.
        Livewire::test(StudySession::class, ['workspaceId' => $this->databases->id, 'sessionId' => $session->id])
            ->call('newQuestion')->assertSet('mode', 'question')->assertSee('Ask a question')
            ->call('save')->assertHasErrors('questionText')
            ->set('questionText', 'What is a surrogate key?')->call('save')->assertSet('mode', null)->assertDispatched('questions-changed');
        $kept = collect($this->questions())->firstWhere('text', 'What is a surrogate key?');
        $this->assertSame([$this->week1->id, $session->id], [$kept->moduleId, $kept->sessionId]);

        $this->board(['moduleId' => $this->week1->id, 'sessionId' => $session->id])
            ->set('text', 'What is a composite key?')->call('add')
            ->assertSeeInOrder(['What is a composite key?', 'Asked earlier, in the module']);
        $asked = collect($this->questions())->firstWhere('text', 'What is a composite key?');
        $this->assertSame([$this->week1->id, $session->id], [$asked->moduleId, $asked->sessionId]);

        // The module's page links to them, with how many are open; they're on the module's questions page.
        $this->actingAs($this->ada)->get(route('workspaces.modules.show', [$this->databases->id, $this->week1->id]))
            ->assertSee(route('workspaces.modules.questions', [$this->databases->id, $this->week1->id]), false)
            ->assertSeeInOrder(['Questions', '2', 'open'])->assertDontSee('What is a composite key?');
        $this->actingAs($this->ada)->get(route('workspaces.modules.questions', [$this->databases->id, $this->week1->id]))
            ->assertOk()->assertSeeInOrder(['Modules', 'Week 1', 'Questions', 'What is a composite key?', 'Asked earlier, in the module']);
    }

    public function test_a_modules_questions_page_filters_searches_and_sorts(): void
    {
        $by = $this->principal($this->ada);
        $questions = app(Questions::class);
        $first = $questions->ask($by, $this->databases->id, 'What is a foreign key?', null, $this->week1->id);
        $this->travel(1)->minutes();
        $second = $questions->ask($by, $this->databases->id, 'Why normalise a table?', null, $this->week1->id);
        $this->travel(1)->minutes();
        $third = $questions->ask($by, $this->databases->id, 'When is a key composite?', null, $this->week1->id);
        $questions->setStatus($by, $second->id, 'stuck');
        $questions->setStatus($by, $third->id, 'answered', 'When one column is not enough to tell rows apart.');

        $board = $this->board(['moduleId' => $this->week1->id])
            ->assertSeeInOrder(['Why normalise a table?', 'What is a foreign key?', 'When is a key composite?'])
            ->set('sort', 'newest')->assertSeeInOrder(['When is a key composite?', 'Why normalise a table?', 'What is a foreign key?'])
            ->set('sort', 'oldest')->assertSeeInOrder(['What is a foreign key?', 'Why normalise a table?', 'When is a key composite?'])
            ->set('search', 'KEY')->assertSee('What is a foreign key?')->assertSee('When is a key composite?')->assertDontSee('Why normalise a table?')
            // The answer is searched too.
            ->set('search', 'rows apart')->assertSee('When is a key composite?')->assertDontSee('What is a foreign key?')
            ->set('search', 'nothing like this')->assertSee('No question matches.')
            ->set('search', '')->call('show', 'stuck')->assertSee('Why normalise a table?')->assertDontSee('What is a foreign key?');

        // ?ask=1, the old way to start one, goes to the page for a new question.
        $this->actingAs($this->ada)->get(route('workspaces.modules.questions', [$this->databases->id, $this->week1->id, 'ask' => 1]))
            ->assertRedirect(route('workspaces.questions.create', [$this->databases->id, 'module' => $this->week1->id]));
    }

    public function test_in_a_session_the_questions_are_folded_until_opened(): void
    {
        $by = $this->principal($this->ada);
        $session = app(Sessions::class)->start($by, $this->databases->id, null, $this->week1->id);
        $this->board(['moduleId' => $this->week1->id, 'sessionId' => $session->id, 'folded' => true])
            ->assertDontSee('Questions')->assertDontSee("What don't you get?", false);
        app(Questions::class)->ask($by, $this->databases->id, 'What is a view?', null, $this->week1->id, $session->id);
        $this->board(['moduleId' => $this->week1->id, 'sessionId' => $session->id, 'folded' => true])
            ->assertSeeInOrder(['Questions', '1 open', 'All questions', 'What is a view?'])
            ->assertSee('aria-expanded', false)->assertSee(route('workspaces.modules.questions', [$this->databases->id, $this->week1->id]), false)
            ->assertDontSee("What don't you get?", false)->assertDontSee('Search the questions');
    }

    public function test_another_students_module_has_no_questions_page(): void
    {
        $bob = $this->student();
        $theirs = app(Modules::class)->create($this->principal($bob), app(Workspaces::class)->create($this->principal($bob), ['name' => 'Theirs'])->id, ['title' => 'Theirs']);
        $this->actingAs($this->ada)->get(route('workspaces.modules.questions', [$this->databases->id, $theirs->id]))->assertNotFound();
    }

    public function test_the_questions_section_holds_every_question_and_a_new_one_has_a_page(): void
    {
        $by = $this->principal($this->ada);
        $joins = app(Topics::class)->create($by, $this->databases->id, 'Joins', $this->week1->id);
        app(Questions::class)->ask($by, $this->databases->id, 'Why does a left join keep unmatched rows?', $joins->id);

        // Asking from a board goes to the page for a new question, about the topic, coming back here.
        $this->board()->call('create', $joins->id)
            ->assertRedirect(route('workspaces.questions.create', [$this->databases->id, 'topic' => $joins->id]));

        // The Questions section holds every question of the course, each saying its module.
        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'questions']))
            ->assertOk()->assertSee('<title>Questions', false)->assertSeeInOrder(['All questions', 'Why does a left join keep unmatched rows?']);
    }

    public function test_the_browser_cannot_reach_another_students_questions_or_change_the_board(): void
    {
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private']);
        $secret = app(Questions::class)->ask($bob, $theirs->id, 'Their question');

        $board = $this->board(['moduleId' => $this->week1->id]);
        $board->call('mark', $secret->id, 'answered');
        $this->assertSame('pending', app(Questions::class)->find($bob, $secret->id)->status);
        $this->assertThrows(fn () => $this->board()->set('moduleId', 'other'), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $this->board()->set('page', true), CannotUpdateLockedPropertyException::class);
    }

    /** @return list<QuestionDetails> */
    private function questions(): array
    {
        return app(Questions::class)->list($this->principal($this->ada), $this->databases->id);
    }

    private function board(array $params = [])
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(QuestionBoard::class, ['workspaceId' => $this->databases->id] + $params);
    }
}
