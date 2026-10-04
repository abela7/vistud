<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\StudyTime;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\CourseHome;
use App\Study\Flashcards;
use App\Study\LearnerProfiles;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The course home: the one line of what to do next, how far the student is, the modules, and Study with no dialog (docs/specs/vistud-2-blueprint.md §3.5.1). */
class CourseHomeScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Operating Systems', 'code' => 'CS301', 'term' => 'Autumn 2026'])->id;
    }

    private function home(): TestResponse
    {
        return $this->actingAs($this->ada)->get(route('workspaces.show', $this->workspace));
    }

    private function module(string $title, array $topics = [], ?string $from = null, ?string $to = null): string
    {
        $id = app(Modules::class)->create($this->by, $this->workspace, ['title' => $title, 'starts_on' => $from, 'ends_on' => $to])->id;
        foreach ($topics as $name => $status) {
            $topic = app(Topics::class)->create($this->by, $this->workspace, $name, $id);
            if ($status !== null) {
                app(Topics::class)->report($this->by, $topic->id, $status);
            }
        }

        return $id;
    }

    public function test_a_new_course_starts_with_the_page_template_and_one_obvious_next_step(): void
    {
        $this->home()->assertOk()
            ->assertSeeInOrder(['CS301 · Autumn 2026', 'Operating Systems'])
            // The template: Back, the title, one primary action, and a ⋯ menu with the rest.
            ->assertSee('Back to </span>All courses', false)->assertSee('More for Operating Systems')
            ->assertSeeInOrder(['Edit course', 'About this course', 'How you learn', 'Instructions for the AI', 'Study with options', 'AI settings', 'Log time'])
            ->assertSeeInOrder(['Next:', 'Add your first module', 'Add module'])
            ->assertSee(route('workspaces.show', [$this->workspace, 'modules']).'?new=1', false)
            // No ring or modules to show yet; what is old on this page is gone.
            ->assertDontSee('id="progress-heading"', false)->assertDontSee('id="modules-heading"', false)
            ->assertDontSee('Streak')->assertDontSee('Last 7 days');
    }

    public function test_the_next_line_follows_the_student_through_the_course(): void
    {
        $one = $this->module('Introduction');
        $this->home()->assertSeeInOrder(['Next:', "Add Module 1's files", 'Open module'])->assertSee(route('workspaces.modules.show', [$this->workspace, $one]), false);

        app(Notes::class)->create($this->by, 'module', $one, 'Lecture 1');
        app(Topics::class)->create($this->by, $this->workspace, 'Processes', $one);
        app(Topics::class)->create($this->by, $this->workspace, 'Threads', $one);
        $this->home()->assertSeeInOrder(['Next:', 'Study Module 1']);

        app(Topics::class)->report($this->by, app(Topics::class)->list($this->by, $this->workspace)[0]->id, 'understood');
        $this->home()->assertSeeInOrder(['Next:', 'Threads: 1 topic left in Module 1']);

        app(Topics::class)->report($this->by, app(Topics::class)->list($this->by, $this->workspace)[1]->id, 'understood');
        $this->module('Processes');
        $this->home()->assertSeeInOrder(['Next:', 'Start Module 2']);
    }

    public function test_a_deadline_within_a_day_comes_first(): void
    {
        $this->module('Introduction');
        app(Activities::class)->create($this->by, $this->workspace, ['kind' => 'assignment', 'title' => 'ER diagram', 'due_on' => '2026-10-07', 'due_time' => '17:00']);

        $this->home()->assertSeeInOrder(['Next:', 'ER diagram is due today', 'Open']);
    }

    public function test_the_ring_the_modules_around_where_the_student_is_and_the_numbers_beside_it(): void
    {
        $m = [];
        foreach (range(1, 8) as $n) {
            $m[$n] = $this->module("Week {$n}", $n === 3 ? ['Processes' => 'understood', 'Threads' => 'understood', 'Scheduling' => 'confused', 'Deadlocks' => null] : []);
        }
        // The student last studied in Week 3.
        $session = app(Sessions::class)->start($this->by, $this->workspace, null, $m[3]);
        app(Sessions::class)->end($this->by, $session->id);

        $page = $this->home()->assertOk()
            ->assertSee('role="progressbar"', false)->assertSee('aria-valuenow="50"', false)->assertSee('2 of 4 topics')
            // Five modules, with the current one among them and marked.
            ->assertSeeInOrder(['Modules', '8', 'All modules', 'Week 2', 'Week 3', '(where you are)', 'Week 4', 'Week 5', 'Week 6'])
            ->assertDontSee('Week 1<')->assertDontSee('Week 7')->assertDontSee('Week 8')
            ->assertSee('Topics understood in Week 3')->assertSee('2/4')
            // Anchored on Week 3: the topic still to do in it.
            ->assertSeeInOrder(['Next:', 'Scheduling: 2 topics left in Module 3']);
        $this->assertSame(1, substr_count($page->getContent(), 'id="modules-heading"'));
    }

    public function test_ten_cards_due_lead_to_the_review(): void
    {
        $one = $this->module('Introduction', ['Processes' => 'understood']);
        app(Notes::class)->create($this->by, 'module', $one, 'Lecture 1');
        $topic = app(Topics::class)->list($this->by, $this->workspace)[0];
        foreach (range(1, 12) as $n) {
            app(Flashcards::class)->add($this->by, $this->workspace, $topic->id, "Question {$n}?", 'Answer.');
        }

        $this->home()->assertSeeInOrder(['Next:', 'Review 12 cards (5 min)', 'Review'])
            ->assertSee('12 cards due')->assertSee(route('workspaces.flashcards.review', $this->workspace), false);
    }

    public function test_an_open_session_is_continued_from_the_header_and_the_next_line(): void
    {
        $one = $this->module('Introduction', ['Processes' => null]);
        $topic = app(Topics::class)->list($this->by, $this->workspace)[0];
        $session = app(Sessions::class)->start($this->by, $this->workspace, $topic->id, $one);

        $this->home()->assertSeeInOrder(['Next:', 'Continue the session on Processes', 'Continue'])
            ->assertSee('Back to your session')->assertSee(route('workspaces.sessions.show', [$this->workspace, $session->id]), false);
    }

    public function test_study_starts_a_session_with_no_dialog_where_the_next_line_points(): void
    {
        $one = $this->module('Introduction', ['Processes' => null, 'Threads' => null]);
        app(Notes::class)->create($this->by, 'module', $one, 'Lecture 1');
        $topics = app(Topics::class)->list($this->by, $this->workspace);
        app(LearnerProfiles::class)->save($this->by, $this->workspace, ['pace' => 'small', 'check' => 'rarely']);

        // The header's Study and the Next line's start the same way: on the next topic of the module the student is in.
        $this->home()->assertSee(e("Livewire.dispatch('study-next', { moduleId: ".json_encode($one).', topicId: '.json_encode($topics[0]->id).' })'), false);

        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        Livewire::test(StudyTime::class, ['workspaceId' => $this->workspace])->dispatch('study-next', moduleId: $one, topicId: $topics[0]->id)
            ->assertRedirect(route('workspaces.sessions.show', [$this->workspace, app(Sessions::class)->current($this->by)->id]));

        $session = app(Sessions::class)->current($this->by);
        $this->assertSame([$topics[0]->id, $one], [$session->topicId, $session->moduleId]);
        // Taught the way the student said they like to learn.
        $this->assertSame(['steps', 'none'], [$session->tutoring['method'], $session->tutoring['check_ins']]);
    }

    public function test_study_with_another_session_open_says_so_instead_of_starting(): void
    {
        $one = $this->module('Introduction', ['Processes' => null]);
        app(Sessions::class)->start($this->by, $this->workspace, null, $one);

        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        Livewire::test(StudyTime::class, ['workspaceId' => $this->workspace])->dispatch('study-next', moduleId: $one)
            ->assertSet('mode', 'busy')->assertNoRedirect();
        $this->assertCount(1, app(Sessions::class)->list($this->by, $this->workspace));
    }

    public function test_the_gatherer_anchors_on_where_the_student_last_studied_else_the_module_running_today_else_the_first(): void
    {
        $one = $this->module('Week 1', [], '2026-09-28', '2026-10-04');
        $two = $this->module('Week 2', [], '2026-10-05', '2026-10-11');
        $three = $this->module('Week 3', [], '2026-10-12', '2026-10-18');
        $current = fn () => collect(app(CourseHome::class)->for($this->by, $this->workspace)->modules)->firstWhere('current', true)['module']->id;

        // Today, Wed 7 Oct, is in Week 2.
        $this->assertSame($two, $current());
        $session = app(Sessions::class)->start($this->by, $this->workspace, null, $three);
        app(Sessions::class)->end($this->by, $session->id);
        $this->assertSame($three, $current());

        // With no dates and no sessions it is the first.
        $other = app(Workspaces::class)->create($this->by, ['name' => 'Chemistry'])->id;
        $first = app(Modules::class)->create($this->by, $other, ['title' => 'A'])->id;
        app(Modules::class)->create($this->by, $other, ['title' => 'B']);
        $this->assertSame($first, collect(app(CourseHome::class)->for($this->by, $other)->modules)->firstWhere('current', true)['module']->id);
        unset($one);
    }

    public function test_another_students_course_is_not_found(): void
    {
        $this->actingAs($this->student())->get(route('workspaces.show', $this->workspace))->assertNotFound();
    }
}
