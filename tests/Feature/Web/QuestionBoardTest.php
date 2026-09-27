<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\QuestionBoard;
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

    public function test_a_question_is_written_in_a_line_then_answered_in_the_panel(): void
    {
        $board = $this->board(['moduleId' => $this->week1->id])
            ->call('add')->assertHasErrors('text')
            ->set('text', 'Why is a primary key never empty?')->call('add')
            ->assertSet('text', '')->assertDispatched('questions-changed')
            ->assertSeeInOrder(['Questions', 'All', '1', 'Pending', '1', 'Why is a primary key never empty?', 'Pending']);
        $question = $this->questions()[0];
        $this->assertSame([$this->week1->id, 'pending'], [$question->moduleId, $question->status]);

        // One click from the row's menu.
        $board->call('mark', $question->id, 'stuck')->assertSeeInOrder(['Why is a primary key never empty?', 'Stuck']);

        // The panel: new words, answered, the answer written down.
        $board->call('open', $question->id, 'answered')->assertDispatched('question-dialog-open')
            ->assertSet('status', 'answered')->assertSee('What you found out, in your own words')
            ->set('question', 'Why can a primary key never be empty?')
            ->set('answer', 'Every row needs a value that names it.')->call('save')
            ->assertDispatched('question-dialog-close')
            ->assertSeeInOrder(['Why can a primary key never be empty?', 'Answered', 'Every row needs a value that names it.']);
        $this->assertSame(['answered', 'Every row needs a value that names it.'], [$this->questions()[0]->status, $this->questions()[0]->answer]);

        $board->call('show', 'pending')->assertSee('None pending.')->assertDontSee('Why can a primary key')
            ->call('show', 'answered')->assertSee('Why can a primary key never be empty?');

        $board->call('open', $question->id)->call('delete')->assertSee('The question is deleted.');
        $this->assertSame([], $this->questions());
    }

    public function test_in_a_session_questions_go_to_its_module_and_the_session(): void
    {
        $by = $this->principal($this->ada);
        $session = app(Sessions::class)->start($by, $this->databases->id, null, $this->week1->id);
        app(Questions::class)->ask($by, $this->databases->id, 'Asked earlier, in the module', null, $this->week1->id, 'an-earlier-session');

        $this->actingAs($this->ada)->get(route('workspaces.sessions.show', [$this->databases->id, $session->id]))
            ->assertOk()->assertSee('Ask a question')->assertSee('1 question open')->assertSee('Asked earlier, in the module')
            ->assertDontSee("What don't you get? Write it down…", false);

        $this->board(['moduleId' => $this->week1->id, 'sessionId' => $session->id])
            ->set('text', 'What is a composite key?')->call('add')
            ->assertSeeInOrder(['What is a composite key?', 'Asked earlier, in the module']);
        $asked = collect($this->questions())->firstWhere('text', 'What is a composite key?');
        $this->assertSame([$this->week1->id, $session->id], [$asked->moduleId, $asked->sessionId]);

        // And they're on the module's page.
        $this->actingAs($this->ada)->get(route('workspaces.modules.show', [$this->databases->id, $this->week1->id]))
            ->assertSeeInOrder(['Questions', 'What is a composite key?']);
    }

    public function test_progress_holds_every_question_and_a_topics_menu_starts_one(): void
    {
        $by = $this->principal($this->ada);
        $joins = app(Topics::class)->create($by, $this->databases->id, 'Joins', $this->week1->id);

        $this->board()->call('create', $joins->id)->assertSet('editing', 'new')->assertSee('New question')
            ->call('save')->assertHasErrors('question')
            ->set('question', 'Why does a left join keep unmatched rows?')->set('status', 'stuck')->set('askTeacher', true)->call('save')
            ->assertSeeInOrder(['Why does a left join keep unmatched rows?', 'Stuck', 'Joins', 'for the teacher']);
        $question = $this->questions()[0];
        $this->assertSame(['stuck', $joins->id, $this->week1->id, true], [$question->status, $question->topicId, $question->moduleId, $question->askTeacher]);

        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'progress']))
            ->assertSeeInOrder(['Questions', 'Why does a left join keep unmatched rows?']);
    }

    public function test_the_browser_cannot_reach_another_students_questions_or_change_the_board(): void
    {
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private']);
        $secret = app(Questions::class)->ask($bob, $theirs->id, 'Their question');

        $board = $this->board(['moduleId' => $this->week1->id]);
        $board->call('open', $secret->id)->assertSet('editing', null)->assertDontSee('Their question');
        $board->call('mark', $secret->id, 'answered');
        $this->assertSame('pending', app(Questions::class)->find($bob, $secret->id)->status);
        $this->assertThrows(fn () => $this->board()->set('moduleId', 'other'), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $this->board()->set('editing', $secret->id), CannotUpdateLockedPropertyException::class);
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
