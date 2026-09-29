<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\Progress;
use App\Models\User;
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

/** A workspace's Progress section: the topic tracker and the questions (docs/specs/study-memory.md §3). */
class ProgressScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $databases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ada = $this->student();
        $this->databases = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Databases']);
    }

    public function test_the_progress_page_shows_topics_by_module_with_their_status_and_evidence(): void
    {
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1: Relational model']);
        $joins = app(Topics::class)->create($by, $this->databases->id, 'Joins', $week1->id);
        app(Topics::class)->create($by, $this->databases->id, 'Normalisation');
        app(Topics::class)->report($by, $joins->id, 'understood');
        app(Questions::class)->ask($by, $this->databases->id, 'Why does a left join keep unmatched rows?', $joins->id);

        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'progress']))
            ->assertOk()
            ->assertSee('<title>Progress · Databases', false)
            ->assertSeeInOrder(['1 not started', '0 covered', '1 understood', '0 confused', '0 mastered'])
            ->assertSeeInOrder(['Week 1: Relational model', 'Joins', 'Understood', 'Evidence: no contact yet · not practised yet', 'Not in a module', 'Normalisation', 'Not started', 'Evidence: no contact yet'])
            ->assertSeeInOrder(['Questions', 'Why does a left join keep unmatched rows?', 'Pending', 'Joins'])
            ->assertDontSee('is coming next');
    }

    public function test_topics_are_added_reported_moved_and_removed_from_the_page(): void
    {
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);

        $page = $this->page()
            ->call('newTopic', $week1->id)->assertDispatched('progress-dialog-open')->assertSet('moduleId', $week1->id)
            ->call('save')->assertHasErrors('name')
            ->set('name', 'Joins')->call('save')->assertDispatched('progress-dialog-close')->assertSee('Joins is added.');
        $joins = $this->topics()[0];
        $this->assertSame($week1->id, $joins->moduleId);

        $page->call('report', $joins->id, 'covered')->assertSeeInOrder(['Joins', 'Covered', 'Evidence: seen, not practised'])
            ->call('report', $joins->id, 'confused')->assertSeeInOrder(['Joins', 'Confused']);
        $this->assertSame('confused', $this->topics()[0]->status);

        $page->call('renameTopic', $joins->id)->assertSet('name', 'Joins')->set('name', 'SQL joins')->call('save')->assertSee('SQL joins is renamed.')
            ->call('moveTopic', $joins->id)->assertSet('moduleId', $week1->id)->set('moduleId', '')->call('save')->assertSee('SQL joins is moved.');
        $this->assertNull($this->topics()[0]->moduleId);

        $page->call('retireTopic', $joins->id)->assertSee('SQL joins is removed.')->assertSee('No topics yet');
    }

    public function test_new_questions_open_the_questions_panel_about_a_topic(): void
    {
        $joins = app(Topics::class)->create($this->principal($this->ada), $this->databases->id, 'Joins');

        $this->page()->assertSee('New question')
            ->call('newQuestion', $joins->id)->assertDispatched('question-new', topicId: $joins->id);
    }

    public function test_the_browser_cannot_change_the_workspace_or_the_dialogs_target(): void
    {
        $joins = app(Topics::class)->create($this->principal($this->ada), $this->databases->id, 'Joins');

        $this->assertThrows(fn () => $this->page()->set('workspaceId', 'other'), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $this->page()->call('renameTopic', $joins->id)->set('targetId', 'other'), CannotUpdateLockedPropertyException::class);
    }

    public function test_another_students_workspace_is_missing(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);
        $topic = app(Topics::class)->create($this->principal($bob), $theirs->id, 'Secret');

        $this->actingAs($this->ada)->get(route('workspaces.show', [$theirs->id, 'progress']))->assertNotFound();
        // Another student's topic isn't trusted on the page for a new question: it starts with none.
        $this->actingAs($this->ada)->get(route('workspaces.questions.create', [$this->databases->id, 'topic' => $topic->id]))
            ->assertOk()->assertDontSee('Secret');
        $this->assertSame([], app(Questions::class)->list($this->principal($bob), $theirs->id));
    }

    private function topics(): array
    {
        return app(Topics::class)->list($this->principal($this->ada), $this->databases->id);
    }

    private function page()
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test(Progress::class, ['workspaceId' => $this->databases->id]);
    }
}
