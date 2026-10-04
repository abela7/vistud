<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\Progress;
use App\Livewire\Workspaces\TopicSheet;
use App\Models\User;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A workspace's Progress section: the tree of modules and topics, its filters, and the questions (docs/specs/vistud-2-blueprint.md §3.5.5). */
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

    public function test_the_progress_page_is_a_tree_the_ring_then_modules_with_their_topics(): void
    {
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1: Relational model']);
        $week2 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 2: Joins']);
        app(Topics::class)->create($by, $this->databases->id, 'Keys', $week1->id);
        $joins = app(Topics::class)->create($by, $this->databases->id, 'Joins', $week1->id);
        app(Topics::class)->create($by, $this->databases->id, 'Views', $week2->id);
        app(Topics::class)->create($by, $this->databases->id, 'Normalisation');
        app(Topics::class)->report($by, $joins->id, 'understood');
        app(Questions::class)->ask($by, $this->databases->id, 'Why does a left join keep unmatched rows?', $joins->id);

        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'progress']))
            ->assertOk()
            ->assertSee('<title>Progress · Databases', false)
            // The ring and the course's number: one of four topics.
            ->assertSeeInOrder(['25 % · 1 of 4 topics'])
            ->assertSeeInOrder(['All', 'Needs attention', 'Not started', 'Mastered'])
            ->assertSeeInOrder(['Week 1: Relational model', '1/2', 'Keys', 'Not started', 'Joins', 'Understood', 'Week 2: Joins', '0/1', 'Views', 'No module', '0/1', 'Normalisation'])
            // The old flat page's words are gone.
            ->assertDontSee('Evidence:')->assertDontSee('findings')->assertDontSee('is coming next')
            ->assertSeeInOrder(['Questions', 'Why does a left join keep unmatched rows?', 'Pending', 'Joins']);
    }

    public function test_the_module_the_student_is_in_is_open_and_the_others_are_folded(): void
    {
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);
        $week2 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 2']);
        app(Topics::class)->create($by, $this->databases->id, 'Keys', $week1->id);
        app(Topics::class)->create($by, $this->databases->id, 'Views', $week2->id);
        $session = app(Sessions::class)->start($by, $this->databases->id, null, $week2->id);
        app(Sessions::class)->end($by, $session->id);

        $html = $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'progress']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/aria-label="Week 2"[^>]*x-data="\{ open: true \}"|x-data="\{ open: true \}"[^>]*aria-label="Week 2"/', $html);
        $this->assertMatchesRegularExpression('/aria-label="Week 1"[^>]*x-data="\{ open: false \}"|x-data="\{ open: false \}"[^>]*aria-label="Week 1"/', $html);
        $this->assertStringContainsString('(where you are)', $html);
    }

    public function test_the_filters_show_what_needs_attention_what_is_not_started_and_what_is_mastered(): void
    {
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);
        $old = app(Topics::class)->create($by, $this->databases->id, 'Deadlocks', $week1->id);
        $fresh = app(Topics::class)->create($by, $this->databases->id, 'Paging', $week1->id);
        app(Topics::class)->create($by, $this->databases->id, 'Segments', $week1->id);
        app(Topics::class)->report($by, $old->id, 'confused');
        $this->travel(8)->days();
        app(Topics::class)->report($by, $fresh->id, 'confused');

        // Confusing for a week or more needs attention; confusing since today does not.
        $this->page()->assertSet('filter', 'all')->assertSee('Needs attention')
            ->call('showOnly', 'attention')->assertSet('filter', 'attention')->assertSee('Deadlocks')->assertDontSee('Paging')->assertDontSee('Segments')
            ->assertSee('Still confusing')
            ->call('showOnly', 'not_started')->assertSee('Segments')->assertDontSee('Deadlocks')
            ->call('showOnly', 'mastered')->assertSee('Nothing is mastered yet.')
            ->call('showOnly', 'nonsense')->assertSet('filter', 'all')->assertSee('Deadlocks')->assertSee('Paging');
        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'progress']).'?filter=attention')
            ->assertOk()->assertSee('Deadlocks')->assertDontSee('Segments');
    }

    public function test_a_status_the_tutor_set_says_so_in_the_chip(): void
    {
        $by = $this->principal($this->ada);
        $topic = app(Topics::class)->create($by, $this->databases->id, 'Deadlocks');
        app(Topics::class)->mark($by, $topic->id, 'understood');

        $this->page()->assertSee('Understood')->assertSee('set by the tutor');
    }

    public function test_a_topic_row_says_how_it_stands_with_its_cards_and_study(): void
    {
        $by = $this->principal($this->ada);
        $topic = app(Topics::class)->create($by, $this->databases->id, 'Deadlocks');
        app(Flashcards::class)->add($by, $this->databases->id, $topic->id, 'What is a deadlock?', 'A cycle of waiting.');

        $page = $this->page()->assertSee('1 card, 1 due')->assertSee('Study')->assertSee('Select');
        $page->call('study', $topic->id)->assertRedirect();
        $this->assertSame($topic->id, app(Sessions::class)->current($by)?->topicId);
    }

    public function test_topics_are_added_moved_reordered_and_removed_from_the_page(): void
    {
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);

        $page = $this->page()
            ->call('newTopic', $week1->id)->assertDispatched('progress-dialog-open')->assertSet('moduleId', $week1->id)
            ->call('save')->assertHasErrors('name')
            ->set('name', 'Joins')->call('save')->assertDispatched('progress-dialog-close')->assertSee('Joins is added.');
        $joins = $this->topics()[0];
        $this->assertSame($week1->id, $joins->moduleId);
        $views = app(Topics::class)->create($by, $this->databases->id, 'Views', $week1->id);

        // Up and down are within the module.
        $page->call('moveTopicBy', $views->id, -1);
        $this->assertSame(['Views', 'Joins'], array_map(fn ($topic) => $topic->name, $this->topics()));
        $page->call('moveTopicBy', $views->id, -1);
        $this->assertSame(['Views', 'Joins'], array_map(fn ($topic) => $topic->name, $this->topics()));

        $page->call('moveTopic', $joins->id)->assertSet('moduleId', $week1->id)->set('moduleId', '')->call('save')->assertSee('Joins is moved.');
        $this->assertNull(collect($this->topics())->firstWhere('id', $joins->id)->moduleId);
        $page->assertSee('No module');

        $page->call('retireTopic', $joins->id)->assertSee('Joins is removed.');
        $page->call('retireTopic', $views->id)->assertSee('No topics yet');
    }

    public function test_a_topics_name_opens_the_topic_sheet_on_the_page(): void
    {
        $topic = app(Topics::class)->create($this->principal($this->ada), $this->databases->id, 'Deadlocks');

        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'progress']))
            ->assertOk()->assertSeeLivewire(TopicSheet::class)
            ->assertSee("topic-sheet-open', { topicId: '{$topic->id}' }", false);
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
        $this->assertThrows(fn () => $this->page()->call('moveTopic', $joins->id)->set('targetId', 'other'), CannotUpdateLockedPropertyException::class);
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
