<?php

namespace Tests\Feature\Web;

use App\Livewire\Study\SessionBar;
use App\Livewire\Workspaces\Progress;
use App\Livewire\Workspaces\StudySession;
use App\Livewire\Workspaces\StudyTime;
use App\Models\User;
use App\Study\Modules;
use App\Study\Notes;
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

/** Study sessions on screen: the Overview's study time, the session page and the top bar's clock (docs/specs/study-memory.md §4). */
class SessionScreensTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private WorkspaceDetails $databases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->databases = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Databases']);
    }

    public function test_a_session_is_started_from_the_overview_and_opens_its_page(): void
    {
        $joins = app(Topics::class)->create($this->principal($this->ada), $this->databases->id, 'Joins');

        $this->actingAs($this->ada)->get(route('workspaces.show', $this->databases->id))
            ->assertOk()->assertSee('Start studying')->assertSeeInOrder(['Study time', 'This week', '0 min', 'In all', '0 min']);

        $this->livewire(StudyTime::class)
            ->call('newSession')->assertDispatched('study-dialog-open')->assertSee('What are you studying?')
            ->set('topicId', $joins->id)->call('save')
            ->assertRedirect(route('workspaces.sessions.show', [$this->databases->id, $this->current()->id]));

        $this->actingAs($this->ada)->get(route('workspaces.sessions.show', [$this->databases->id, $this->current()->id]))
            ->assertOk()
            ->assertSee('<title>Study session · Databases', false)
            ->assertSeeInOrder(['Joins', 'Started Mon 5 Oct, 09:00', 'Studying', '0:00:00', 'Pause', 'Take a break', 'End session'])
            ->assertSeeInOrder(['What happened', 'Studied', '09:00–now'])
            ->assertSee('data-session-heartbeat', false);

        $this->actingAs($this->ada)->get(route('workspaces.show', $this->databases->id))->assertSee('Back to your session')->assertDontSee('Start studying');
    }

    public function test_the_session_page_pauses_breaks_resumes_and_ends_with_the_topics_status(): void
    {
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);
        $joins = app(Topics::class)->create($by, $this->databases->id, 'Joins', $week1->id);
        app(Notes::class)->create($by, 'module', $week1->id, 'Lecture 3: joins');
        $session = app(Sessions::class)->start($by, $this->databases->id, $joins->id);

        $page = $this->page($session->id)->assertSeeInOrder(['Material', 'Week 1', 'Lecture 3: joins']);
        $this->travel(25)->minutes();
        $page->call('pause')->assertDispatched('session-changed')->assertSee('Paused')->assertSee('Resume');
        $this->travel(5)->minutes();
        $page->call('takeBreak')->assertSee('On a break')->assertSee('Back to studying');
        $this->travel(10)->minutes();
        $page->call('resume')->assertSee('Studying');
        $this->travel(20)->minutes();

        $page->call('confirmEnd')->assertDispatched('session-dialog-open')->assertSee('End this session?')->assertSee('45 min')
            ->set('topicStatus', 'understood')->call('save')
            ->assertSee('Session ended. You studied 45 min.')
            ->assertSeeInOrder(['Studied', '09:00', '09:25', '25 min', 'Paused', 'Break', '09:30', '09:40', '10 min', 'Studied', '09:40', '10:00', '20 min'])
            ->assertSee('Studied 45 min, with 10 min of breaks')
            ->assertDontSee('Take a break');
        $this->assertSame('understood', app(Topics::class)->find($by, $joins->id)->status);
    }

    public function test_the_page_says_when_the_clock_paused_itself_and_the_time_can_be_counted_back(): void
    {
        $session = app(Sessions::class)->start($this->principal($this->ada), $this->databases->id);
        $this->travel(Sessions::IDLE_MINUTES + 10)->minutes();

        $this->page($session->id)
            ->assertSee('Paused while you were away')->assertSee('Nothing happened here after 09:00')
            ->call('countAway')->assertSee('The time away is counted as study.')->assertSee('Studying')->assertDontSee('Paused while you were away');
        $this->assertSame((Sessions::IDLE_MINUTES + 10) * 60, $this->current()->studySeconds);
    }

    public function test_time_is_logged_afterwards_and_shows_in_the_totals(): void
    {
        $this->livewire(StudyTime::class)
            ->call('logTime')->assertSet('date', '2026-10-05')->assertSet('time', '08:00')
            ->set('minutes', '0')->call('save')->assertHasErrors('minutes')
            ->set('date', '2026-10-04')->set('time', '14:00')->set('minutes', '90')->call('save')
            ->assertSee('1 h 30 min logged.')
            // Sunday the 4th is last week: weeks start on Monday.
            ->assertSeeInOrder(['This week', '0 min', 'In all', '1 h 30 min', 'Latest sessions', 'Study session', 'Sun 4 Oct, 14:00', '1 h 30 min']);
    }

    public function test_the_top_bar_shows_the_open_session_on_every_page_and_pauses_it(): void
    {
        $this->actingAs($this->ada)->get(route('home'))->assertOk()->assertDontSee('session-pill');
        $session = app(Sessions::class)->start($this->principal($this->ada), $this->databases->id);
        $this->travel(10)->minutes();

        $this->actingAs($this->ada)->get(route('home'))->assertOk()
            ->assertSee('session-pill', false)->assertSee('Databases')->assertSee('data-base="600"', false)->assertSee('Pause the session');

        $this->travel(20)->minutes();
        $bar = $this->livewire(SessionBar::class)->call('heartbeat');
        $this->travel(20)->minutes();
        // The heartbeat kept it running: 50 minutes in, nothing paused.
        $this->assertSame(['running', 3000], [$this->current()->state, $this->current()->studySeconds]);

        $bar->call('pause')->assertDispatched('session-changed')->assertSee('Resume the session');
        $this->assertSame('paused', app(Sessions::class)->find($this->principal($this->ada), $session->id)->state);
        $bar->call('resume')->assertSee('Pause the session');
    }

    public function test_a_topic_is_studied_straight_from_progress_and_a_second_session_is_refused(): void
    {
        $joins = app(Topics::class)->create($this->principal($this->ada), $this->databases->id, 'Joins');

        $this->livewire(Progress::class)->call('study', $joins->id)
            ->assertRedirect(route('workspaces.sessions.show', [$this->databases->id, $this->current()->id]));
        $this->assertSame($joins->id, $this->current()->topicId);

        $this->livewire(Progress::class)->call('study', $joins->id)->assertSee('Another study session is still open.');
    }

    public function test_a_session_started_by_mistake_is_deleted(): void
    {
        $session = app(Sessions::class)->start($this->principal($this->ada), $this->databases->id);

        $this->page($session->id)->call('confirmDelete')->assertSee('Delete this session?')
            ->call('save')->assertRedirect(route('workspaces.show', $this->databases->id));
        $this->assertNull($this->current());
    }

    public function test_another_students_session_and_the_locked_ids(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);
        $session = app(Sessions::class)->start($this->principal($bob), $theirs->id);
        $mine = app(Sessions::class)->start($this->principal($this->ada), $this->databases->id);
        $maths = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Maths']);

        $this->actingAs($this->ada)->get(route('workspaces.sessions.show', [$theirs->id, $session->id]))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.sessions.show', [$this->databases->id, $session->id]))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.sessions.show', [$maths->id, $mine->id]))->assertNotFound();
        $this->assertThrows(fn () => $this->page($mine->id)->set('sessionId', $session->id), CannotUpdateLockedPropertyException::class);
    }

    private function current()
    {
        return app(Sessions::class)->current($this->principal($this->ada));
    }

    private function page(string $sessionId)
    {
        return $this->livewire(StudySession::class, ['sessionId' => $sessionId]);
    }

    private function livewire(string $class, array $params = [])
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test($class, $class === SessionBar::class ? $params : ['workspaceId' => $this->databases->id] + $params);
    }
}
