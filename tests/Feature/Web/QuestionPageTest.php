<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\QuestionPage;
use App\Models\User;
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\Questions;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A question on a page of its own, and a new one (docs/specs/study-memory.md §3). */
class QuestionPageTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $databases;

    private ModuleDetails $week1;

    private ModuleDetails $week2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ada = $this->student();
        $by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $this->week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);
        $this->week2 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 2']);
    }

    public function test_a_question_opens_on_a_page_of_its_own_with_its_options(): void
    {
        $by = $this->principal($this->ada);
        $asked = app(Questions::class)->ask($by, $this->databases->id, 'Why is a key never empty?', null, $this->week1->id);

        $this->actingAs($this->ada)->get(route('workspaces.questions.show', [$this->databases->id, $asked->id]))
            ->assertOk()->assertSee('<title>Why is a key never empty? · Questions · Databases', false)
            ->assertSeeInOrder(['Databases · Week 1', 'Question', 'Answer', 'Status', 'Pending', 'Stuck', 'Answered', 'Module', 'Ask the teacher', 'Asked', 'Delete this question', 'Save'])
            ->assertSee(route('workspaces.modules.questions', [$this->databases->id, $this->week1->id]), false);

        // ?status=answered opens it ready to be answered.
        $this->actingAs($this->ada)->get(route('workspaces.questions.show', [$this->databases->id, $asked->id, 'status' => 'answered']))
            ->assertOk()->assertSee('value="answered"', false);
    }

    public function test_another_students_question_or_one_from_another_workspace_is_missing(): void
    {
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private']);
        $secret = app(Questions::class)->ask($bob, $theirs->id, 'Their question');
        $mine = app(Questions::class)->ask($this->principal($this->ada), $this->databases->id, 'Mine');
        $other = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Other']);

        $this->actingAs($this->ada)->get(route('workspaces.questions.show', [$this->databases->id, $secret->id]))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.questions.show', [$theirs->id, $secret->id]))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.questions.show', [$other->id, $mine->id]))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.questions.create', [$theirs->id]))->assertNotFound();
    }

    public function test_a_question_is_changed_answered_moved_and_deleted_on_its_page(): void
    {
        $by = $this->principal($this->ada);
        $questions = app(Questions::class);
        $asked = $questions->ask($by, $this->databases->id, 'Why is a key never empty?', null, $this->week1->id);

        $page = $this->page(['questionId' => $asked->id, 'withStatus' => 'answered'])
            ->assertSet('status', 'answered')->assertSet('moduleId', $this->week1->id)
            ->set('question', '')->call('save')->assertHasErrors('question')
            ->set('question', 'Why can a key never be empty?')->set('answer', 'Every row needs a value that names it.')
            ->set('moduleId', $this->week2->id)->set('askTeacher', true)->call('save')
            ->assertHasNoErrors()->assertSee('The question is saved.');
        $saved = $questions->find($by, $asked->id);
        $this->assertSame(
            ['Why can a key never be empty?', 'answered', 'Every row needs a value that names it.', $this->week2->id, true],
            [$saved->text, $saved->status, $saved->answer, $saved->moduleId, $saved->askTeacher],
        );

        $page->call('delete')->assertRedirect(route('workspaces.modules.questions', [$this->databases->id, $this->week2->id]));
        $this->assertSame([], $questions->list($by, $this->databases->id));
    }

    public function test_a_new_question_is_made_by_its_first_save_and_goes_back_where_it_came_from(): void
    {
        $by = $this->principal($this->ada);
        $joins = app(Topics::class)->create($by, $this->databases->id, 'Joins', $this->week1->id);

        // Started from a topic: the topic and its module are chosen already.
        $from = route('workspaces.show', [$this->databases->id, 'progress']);
        $this->actingAs($this->ada)->get(route('workspaces.questions.create', [$this->databases->id, 'topic' => $joins->id, 'from' => '/workspaces/'.$this->databases->id.'/progress']))
            ->assertOk()->assertSee('New question')->assertSee('Databases · Week 1');
        $this->page(['inModule' => null, 'aboutTopic' => $joins->id, 'from' => '/workspaces/'.$this->databases->id.'/progress'])
            ->assertSet('topicId', $joins->id)->assertSet('moduleId', $this->week1->id)
            ->call('save')->assertHasErrors('question')
            ->set('question', 'Why does a left join keep unmatched rows?')->set('status', 'stuck')->set('askTeacher', true)->call('save')
            ->assertRedirect($from);
        $question = app(Questions::class)->list($by, $this->databases->id)[0];
        $this->assertSame(['stuck', $joins->id, $this->week1->id, true], [$question->status, $question->topicId, $question->moduleId, $question->askTeacher]);

        // With no page to go back to, it's the module's questions; a page of another site or workspace isn't one.
        $mine = $this->page(['inModule' => $this->week2->id])->set('question', 'What is a view?')->call('save')
            ->assertRedirect(route('workspaces.modules.questions', [$this->databases->id, $this->week2->id]));
        foreach (['https://evil.example/workspaces/'.$this->databases->id.'/x', '//evil.example', '/workspaces/other/progress', '/workspaces/'.$this->databases->id.'/../x'] as $from) {
            $this->page(['inModule' => $this->week2->id, 'from' => $from])->assertSet('from', null);
        }
        // A module or topic that isn't in this workspace is ignored, not trusted.
        $this->page(['inModule' => 'nothing-here', 'aboutTopic' => 'nothing-here'])->assertSet('moduleId', '')->assertSet('topicId', '');
        $this->assertThrows(fn () => $this->page()->set('workspaceId', 'other'), CannotUpdateLockedPropertyException::class);
        $this->assertNotNull($mine);
    }

    private function page(array $params = [])
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(QuestionPage::class, ['workspaceId' => $this->databases->id] + $params);
    }
}
