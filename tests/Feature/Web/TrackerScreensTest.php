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

    public function test_the_overview_is_short_what_to_pick_up_and_whats_coming(): void
    {
        $by = $this->principal($this->ada);
        $keys = app(Topics::class)->create($by, $this->databases->id, 'Primary and foreign keys');
        app(Topics::class)->report($by, $keys->id, 'confused');
        app(Notes::class)->create($by, 'workspace', $this->databases->id, 'Lecture 3: joins');
        app(Activities::class)->create($by, $this->databases->id, ['title' => 'ER diagram', 'due_on' => now()->addDay()->toDateString()]);
        app(InstructionTexts::class)->set($by, "workspace:{$this->databases->id}", 'Teach slide by slide.');

        $this->actingAs($this->ada)->get(route('workspaces.show', $this->databases->id))
            ->assertOk()
            ->assertSeeInOrder(['Databases', 'Edit course', 'About this course', 'How you learn', 'Instructions for the AI', 'Log time', 'Study'])
            // The one line of what to do next, first; the rhythm moved to Progress.
            ->assertSeeInOrder(['Next:', 'Add your first module'])
            ->assertDontSee('Streak')
            ->assertSeeInOrder(['Continue', 'Lecture 3: joins'])
            ->assertSeeInOrder(['Coming up', 'ER diagram', 'Assignment', 'Due tomorrow'])
            // Topics live in Progress, and the instructions in their dialog.
            ->assertDontSee('Primary and foreign keys')
            ->assertDontSee('Teach slide by slide.');
    }

    public function test_coming_up_groups_what_is_due_late_first_and_shows_six_at_most(): void
    {
        Carbon::setTestNow('2026-10-01 09:00');
        $by = $this->principal($this->ada);
        foreach ([
            'Overdue essay' => '2026-09-28', 'Overdue lab' => '2026-09-30',
            'Soon quiz' => '2026-10-02', 'Soon essay' => '2026-10-04', 'Soon lab' => '2026-10-07',
            'Distant a' => '2026-10-20', 'Distant b' => '2026-10-25', 'Distant c' => '2026-11-10', 'Distant d' => '2026-11-20',
        ] as $title => $due) {
            app(Activities::class)->create($by, $this->databases->id, ['title' => $title, 'due_on' => $due]);
        }

        $this->livewire(Tasks::class)
            ->assertSeeInOrder(['Coming up', '2 late', 'Late', 'Overdue essay', 'Overdue lab', 'Next 7 days', 'Soon quiz', 'Soon essay', 'Soon lab', 'Later', 'Distant a'])
            ->assertDontSee('Distant b')->assertDontSee('Distant c')->assertDontSee('Distant d')
            ->assertSee('3 more · All assignments');

        // With nothing late there is no late mark, and with few tasks nothing is hidden.
        foreach (app(Activities::class)->list($by, $this->databases->id) as $task) {
            if ($task->dueOn < '2026-10-01' || $task->dueOn > '2026-10-02') {
                app(Activities::class)->delete($by, $task->id);
            }
        }
        $this->livewire(Tasks::class)->assertSee('Soon quiz')->assertDontSee('late')->assertDontSee('more ·')->assertDontSee('Later');
    }

    public function test_the_overview_lists_the_modules_with_dates_and_topics_understood_five_at_most_ended_ones_last(): void
    {
        Carbon::setTestNow('2026-10-10 09:00');
        $by = $this->principal($this->ada);
        $modules = app(Modules::class);
        $topics = app(Topics::class);
        foreach ([
            'Week 1' => ['2026-09-28', '2026-10-04'], 'Week 2' => ['2026-10-05', '2026-10-09'], 'Week 3' => ['2026-10-10', '2026-10-16'],
            'Week 4' => ['2026-10-17', '2026-10-23'], 'Week 5' => ['2026-10-24', '2026-10-30'], 'Week 6' => ['2026-10-31', '2026-11-06'], 'Week 7' => [null, null],
        ] as $title => [$from, $to]) {
            $module = $modules->create($by, $this->databases->id, ['title' => $title, 'starts_on' => $from, 'ends_on' => $to]);
            if ($title === 'Week 3') {
                $topics->report($by, $topics->create($by, $this->databases->id, 'Joins', $module->id)->id, 'understood');
                $topics->create($by, $this->databases->id, 'Keys', $module->id);
            }
        }

        // Five show, around where the student is (Week 3 runs today): one before it, and the three after.
        $page = $this->actingAs($this->ada)->get(route('workspaces.show', $this->databases->id))->assertOk()
            ->assertSeeInOrder(['Modules', '7', 'All modules', 'Week 2', 'Week 3', '(where you are)', '10 Oct – 16 Oct', 'Week 4', 'Week 5', 'Week 6'])
            ->assertSee('Topics understood in Week 3')->assertSee('aria-valuenow="1"', false)->assertSee('1/2')
            ->assertDontSee('Week 1')->assertDontSee('Week 7');
        $this->assertSame(1, substr_count($page->getContent(), 'id="modules-heading"'));
    }

    public function test_a_new_course_overview_points_to_the_first_module_and_tasks(): void
    {
        $this->actingAs($this->ada)->get(route('workspaces.show', $this->databases->id))
            ->assertOk()
            ->assertSeeInOrder(['Next:', 'Add your first module', 'Add module'])
            ->assertSee(route('workspaces.show', [$this->databases->id, 'modules']).'?new=1', false)
            ->assertSee('Nothing due')->assertSee('Add an assignment')
            // The Modules box would only repeat the first module's prompt.
            ->assertDontSee('All modules');
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
            ->call('setStatus', $task->id, 'done')->assertSee('All done.')->assertSee('Show 1 done')
            ->call('toggleDone')->assertSee('aria-pressed="true"', false)
            ->call('confirmDelete', $task->id)->assertSee('Delete “ER diagram”?')
            ->call('save')->assertSee('ER diagram is deleted.');
        $this->assertSame([], app(Activities::class)->list($this->principal($this->ada), $this->databases->id));
    }

    public function test_instructions_are_written_in_one_dialog(): void
    {
        $this->livewire(Instructions::class)
            ->call('edit')->assertDispatched('instructions-dialog-open')->assertSeeInOrder(['Instructions for the AI', 'About you', 'This course'])
            ->set('me', "Second-year nursing student.\nUse everyday examples.")->set('course', str_repeat('a', 2001))
            ->call('save')->assertHasErrors('course')
            ->set('course', 'Go slide by slide.')->call('save')->assertDispatched('instructions-dialog-close')->assertSee('Instructions saved.')
            ->call('edit')->assertSet('course', 'Go slide by slide.');

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
            ->assertSeeInOrder(['Week 1', '1 link']);
        $this->contents('module', $module->id)->set('tab', 'files')->assertSeeInOrder(['Week 1', 'youtube.com', 'youtube.com'])
            ->assertSee('target="_blank" rel="noopener noreferrer"', false);
        $link = app(Links::class)->list($by, $this->databases->id)[0];

        $page->call('editLink', $link->id)->assertSet('url', 'https://www.youtube.com/watch?v=joins')
            ->set('name', 'Joins explained')->call('save')->assertSee('“Joins explained” is saved.')
            ->call('moveLink', $link->id)->assertSet('destination', "module:{$module->id}")
            ->set('destination', "workspace:{$this->databases->id}")->call('save')->assertSee('“Joins explained” is moved.');
        $this->assertNull(app(Links::class)->find($by, $link->id)->moduleId);

        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'notes']))
            ->assertSeeInOrder(['New', 'Joins explained', 'youtube.com']);

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
        $this->assertThrows(fn () => $this->livewire(Instructions::class)->call('edit')->set('editing', false), CannotUpdateLockedPropertyException::class);
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

    private function contents(string $view, ?string $placeId = null)
    {
        return $this->livewire(Contents::class, ['view' => $view, 'placeId' => $placeId]);
    }

    private function livewire(string $class, array $params = [])
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test($class, ['workspaceId' => $this->databases->id] + $params);
    }
}
