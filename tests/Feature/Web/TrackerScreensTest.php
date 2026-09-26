<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\Contents;
use App\Livewire\Workspaces\Instructions;
use App\Livewire\Workspaces\Progress;
use App\Livewire\Workspaces\Tasks;
use App\Models\User;
use App\Study\Activities;
use App\Study\Findings;
use App\Study\Instructions as InstructionTexts;
use App\Study\Links;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The tracker's screens from step 1b: the Overview, tasks, instructions, findings and links (docs/specs/study-memory.md §3). */
class TrackerScreensTest extends TestCase
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

    public function test_the_overview_says_where_the_student_is(): void
    {
        $by = $this->principal($this->ada);
        $keys = app(Topics::class)->create($by, $this->databases->id, 'Primary and foreign keys');
        $joins = app(Topics::class)->create($by, $this->databases->id, 'Joins');
        app(Topics::class)->report($by, $keys->id, 'confused');
        app(Topics::class)->report($by, $joins->id, 'understood');
        $question = app(Questions::class)->ask($by, $this->databases->id, 'Why does a left join keep unmatched rows?');
        app(Questions::class)->setAskTeacher($by, $question->id, true);
        app(Notes::class)->create($by, 'workspace', $this->databases->id, 'Lecture 3: joins');
        app(Activities::class)->create($by, $this->databases->id, ['title' => 'ER diagram', 'due_on' => now()->addDay()->toDateString()]);
        app(InstructionTexts::class)->set($by, "workspace:{$this->databases->id}", 'Teach slide by slide.');

        $this->actingAs($this->ada)->get(route('workspaces.show', $this->databases->id))
            ->assertOk()
            ->assertSeeInOrder(['Where you are', 'Open Progress', '0 not started', '0 covered', '1 understood', '1 confused', '0 mastered'])
            ->assertSeeInOrder(['Still confusing', 'Primary and foreign keys', 'Open questions', '(1)', 'Why does a left join keep unmatched rows?', 'for the teacher'])
            ->assertSeeInOrder(['Assignments and tasks', '(1 to do)', 'ER diagram', 'Assignment', 'Due tomorrow'])
            ->assertSeeInOrder(['Instructions for the assistant', 'About you', 'Not written yet', 'This course', 'Teach slide by slide.'])
            ->assertSeeInOrder(['Pick up where you left off', 'Lecture 3: joins'])
            ->assertSeeInOrder(['About', 'Modules', 'Calendar', 'Coming next']);
    }

    public function test_a_new_course_overview_invites_the_first_topics_and_tasks(): void
    {
        $this->actingAs($this->ada)->get(route('workspaces.show', $this->databases->id))
            ->assertOk()
            ->assertSee('No topics yet.')
            ->assertSee('Nothing yet. Add assignments')
            ->assertDontSee('Pick up where you left off')
            ->assertDontSee('Still confusing');
    }

    public function test_assignments_and_tasks_are_added_edited_ticked_and_deleted(): void
    {
        Carbon::setTestNow('2026-10-01 09:00');
        $module = app(Modules::class)->create($this->principal($this->ada), $this->databases->id, ['title' => 'Week 1']);

        $page = $this->livewire(Tasks::class)
            ->call('newTask')->assertDispatched('tasks-dialog-open')->assertSee('New assignment or task')
            ->call('save')->assertHasErrors('title')
            ->set('title', 'ER diagram')->set('kind', 'assignment')->set('dueOn', '2026-10-03')->set('moduleId', $module->id)
            ->call('save')->assertDispatched('tasks-dialog-close')->assertSee('ER diagram is added.')
            ->assertSeeInOrder(['ER diagram', 'Assignment', 'Week 1', 'Due Sat 3 Oct']);
        $task = app(Activities::class)->list($this->principal($this->ada), $this->databases->id)[0];

        $page->call('setStatus', $task->id, 'doing')->assertSee('In progress')
            ->call('editTask', $task->id)->assertSet('title', 'ER diagram')->assertSet('dueOn', '2026-10-03')
            ->set('dueOn', '2026-09-30')->call('save')->assertSee('Was due 30 Sep')
            ->call('setStatus', $task->id, 'done')->assertSee('(0 to do)')->assertSee('All done.')->assertSee('Show 1 done')
            ->call('toggleDone')->assertSee('aria-pressed="true"', false)
            ->call('confirmDelete', $task->id)->assertSee('Delete “ER diagram”?')
            ->call('save')->assertSee('ER diagram is deleted.');
        $this->assertSame([], app(Activities::class)->list($this->principal($this->ada), $this->databases->id));
    }

    public function test_instructions_are_written_on_the_overview(): void
    {
        $this->livewire(Instructions::class)
            ->call('edit', 'me')->assertDispatched('instructions-dialog-open')->assertSee('What should the assistant know about you')
            ->set('text', "Second-year nursing student.\nUse everyday examples.")->call('save')
            ->assertSee('What the assistant knows about you is saved.')->assertSee('Second-year nursing student.')
            ->call('edit', 'workspace')->assertSet('text', '')->set('text', str_repeat('a', 2001))->call('save')->assertHasErrors('text')
            ->set('text', 'Go slide by slide.')->call('save')->assertSee('The instructions for this course are saved.');

        $this->assertSame(
            ['me' => "Second-year nursing student.\nUse everyday examples.", 'workspace' => 'Go slide by slide.', 'module' => ''],
            app(InstructionTexts::class)->forSession($this->principal($this->ada), $this->databases->id),
        );
    }

    public function test_findings_are_pinned_under_their_topic_in_progress(): void
    {
        $by = $this->principal($this->ada);
        $joins = app(Topics::class)->create($by, $this->databases->id, 'Joins');
        $note = app(Notes::class)->create($by, 'workspace', $this->databases->id, 'Lecture 3');

        $page = $this->livewire(Progress::class)
            ->call('newFinding', $joins->id)->assertDispatched('progress-dialog-open')->assertSee('New finding · Joins')->assertSee('Lecture 3')
            ->call('save')->assertHasErrors('text')
            ->set('text', 'A left join keeps every row of the left table.')->set('source', "note:{$note->id}")->set('locator', 'slide 12')
            ->call('save')->assertSee('The finding is added.')
            ->assertSeeInOrder(['Joins', '1 finding', 'A left join keeps every row of the left table.', 'From', 'Lecture 3', ', slide 12']);
        $finding = app(Findings::class)->byTopic($by, $this->databases->id)[$joins->id][0];

        $page->call('toggleFindings', $joins->id)->assertDontSee('A left join keeps every row')->assertSee('1 finding')
            ->call('toggleFindings', $joins->id)
            ->call('editFinding', $finding->id)->assertSet('text', 'A left join keeps every row of the left table.')->assertSet('source', "note:{$note->id}")
            ->set('text', 'LEFT JOIN keeps the left rows.')->call('save')->assertSee('The finding is saved.')->assertSee('LEFT JOIN keeps the left rows.')
            ->call('deleteFinding', $finding->id)->assertSee('The finding is removed.')->assertDontSee('1 finding');

        // What a study session wrote says so.
        app(Findings::class)->add($by, $joins->id, ['text' => 'Inner joins keep only matches.'], 'ai');
        $page->call('$refresh')->assertSee('From a study session');
    }

    public function test_links_sit_beside_notes_and_files(): void
    {
        $by = $this->principal($this->ada);
        $module = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);

        $page = $this->contents('modules')
            ->call('newLink', 'module', $module->id)->assertDispatched('structure-dialog-open')->assertSee('Add a link to Week 1')
            ->set('url', 'javascript:alert(1)')->call('save')->assertHasErrors('url')
            ->set('url', 'www.youtube.com/watch?v=joins')->call('save')->assertSee('“youtube.com” is added.')
            ->assertSeeInOrder(['Week 1', '1 link', 'youtube.com', 'Link', 'youtube.com'])
            ->assertSee('target="_blank" rel="noopener noreferrer"', false);
        $link = app(Links::class)->list($by, $this->databases->id)[0];

        $page->call('editLink', $link->id)->assertSet('url', 'https://www.youtube.com/watch?v=joins')
            ->set('name', 'Joins explained')->call('save')->assertSee('“Joins explained” is saved.')
            ->call('moveLink', $link->id)->assertSet('destination', "module:{$module->id}")
            ->set('destination', "workspace:{$this->databases->id}")->call('save')->assertSee('“Joins explained” is moved.');
        $this->assertNull(app(Links::class)->find($by, $link->id)->moduleId);

        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'notes']))
            ->assertSeeInOrder(['0 notes · 0 files · 1 link', 'Add link', 'Not in a module', 'Joins explained']);

        $this->contents('notes')->call('confirmDelete', 'link', $link->id)->assertSee('Delete “Joins explained”?')
            ->call('save')->assertSee('“Joins explained” is deleted.');
        $this->assertSame([], app(Links::class)->list($by, $this->databases->id));
    }

    public function test_a_module_has_its_own_instructions(): void
    {
        $module = app(Modules::class)->create($this->principal($this->ada), $this->databases->id, ['title' => 'Week 6: Revision']);

        $this->contents('modules')
            ->call('editInstructions', $module->id)->assertSee('Instructions for Week 6: Revision')
            ->set('instructions', 'Quiz me more than you explain.')->call('save')
            ->assertSee('The instructions for Week 6: Revision are saved.')
            ->call('editInstructions', $module->id)->assertSet('instructions', 'Quiz me more than you explain.');

        $this->assertSame('Quiz me more than you explain.', app(InstructionTexts::class)->get($this->principal($this->ada), "module:{$module->id}"));
    }

    public function test_the_browser_cannot_change_what_the_new_components_act_on(): void
    {
        $task = app(Activities::class)->create($this->principal($this->ada), $this->databases->id, ['title' => 'ER diagram']);

        $this->assertThrows(fn () => $this->livewire(Tasks::class)->set('workspaceId', 'other'), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $this->livewire(Tasks::class)->call('editTask', $task->id)->set('targetId', 'other'), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $this->livewire(Instructions::class)->call('edit', 'me')->set('editing', 'workspace'), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $this->livewire(Progress::class)->set('findingId', 'other'), CannotUpdateLockedPropertyException::class);
    }

    public function test_another_students_things_cannot_be_reached(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);
        $task = app(Activities::class)->create($this->principal($bob), $theirs->id, ['title' => 'Theirs']);

        $this->assertThrows(fn () => $this->livewire(Tasks::class)->call('setStatus', $task->id, 'done'));
        $this->assertSame('todo', app(Activities::class)->find($this->principal($bob), $task->id)->status);
        $this->actingAs($this->ada)->get(route('workspaces.show', $theirs->id))->assertNotFound();
    }

    private function contents(string $view)
    {
        return $this->livewire(Contents::class, ['view' => $view]);
    }

    private function livewire(string $class, array $params = [])
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test($class, ['workspaceId' => $this->databases->id] + $params);
    }
}
