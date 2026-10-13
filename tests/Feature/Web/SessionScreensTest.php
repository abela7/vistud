<?php

namespace Tests\Feature\Web;

use App\Livewire\Study\SessionBar;
use App\Livewire\Workspaces\Progress;
use App\Livewire\Workspaces\StudySession;
use App\Livewire\Workspaces\StudyTime;
use App\Models\User;
use App\Platform\Errors\NotFound;
use App\Study\Files;
use App\Study\Folders;
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
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Study sessions on screen: Home's study time, the session page and the top bar's clock (docs/specs/study-memory.md §4). */
class SessionScreensTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

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
            ->assertOk()->assertSee('Study with options')->assertSee('Next:');

        // The student's rhythm sits at the bottom of Progress.
        $this->actingAs($this->ada)->get(route('workspaces.show', [$this->databases->id, 'progress']))
            ->assertOk()->assertSeeInOrder(['Streak', '0 days', 'Last 7 days', '0 min', 'Cards to review', '0']);

        $this->livewire(StudyTime::class)
            ->call('newSession')->assertDispatched('study-dialog-open')->assertSee('Topic (optional)')
            ->set('topicId', $joins->id)->call('save')
            ->assertRedirect(route('workspaces.sessions.show', [$this->databases->id, $this->current()->id]));

        $this->actingAs($this->ada)->get(route('workspaces.sessions.show', [$this->databases->id, $this->current()->id]))
            ->assertOk()
            ->assertSee('<title>Study session · Databases', false)
            ->assertSeeInOrder(['Joins', 'Started Mon 5 Oct, 09:00', 'Studying', '0:00:00', 'Pause', 'End'])
            // The ⋯ menu holds the settings; the copy-paste tools are hidden until the student says they use another AI.
            ->assertSeeInOrder(['How the AI teaches', 'Use the Pomodoro clock', 'Take a break', 'Delete session'])
            ->assertDontSee('Prompt for another AI')->assertDontSee('Save from another AI')
            // The tiles, the questions board and the timeline are gone: the chat is the page.
            ->assertDontSee('Another AI')->assertDontSee('New flashcard')->assertDontSee('What happened')->assertDontSee('action-tile', false)
            ->assertSee('Set up your AI first')->assertSee('Material')->assertSee('This session')
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

        // The rail lists the module's topics and material; the material panel lists all of it.
        $page = $this->page($session->id)->assertSeeInOrder(['Topics in Week 1', 'Joins', 'Material', 'Lecture 3: joins', 'This session'])
            ->call('openMaterial')->assertDispatched('session-dialog-open')->assertSee('Material for the tutor')
            ->call('close');
        $this->travel(25)->minutes();
        $page->call('pause')->assertDispatched('session-changed')->assertSee('Paused')->assertSee('Resume');
        $this->travel(5)->minutes();
        $page->call('takeBreak')->assertSee('On a break')->assertSee('Back to studying');
        $this->travel(10)->minutes();
        $page->call('resume')->assertSee('Studying');
        $this->travel(20)->minutes();

        // One screen: the time, where the student stands on each topic touched (a choice for each), then, once ended, how it went.
        $page->call('confirmEnd')->assertDispatched('session-dialog-open')->assertSee('End this session?')->assertSee('You studied 45 min, with 10 min of breaks.')
            ->assertSee('Where you stand')->assertSeeInOrder(['Joins', 'No status yet.', 'Leave it', 'Studying', 'Understood', 'Not understood'])
            ->assertSet('statuses', [$joins->id => ''])
            ->set('statuses.'.$joins->id, 'understood')->call('save')
            ->assertSet('mode', 'done')->assertSee('Session ended')->assertSee('You studied 45 min, with 10 min of breaks.')
            ->assertDontSee('Take a break');
        $page->call('close');
        $this->assertSame('understood', app(Topics::class)->find($by, $joins->id)->status);
    }

    public function test_the_notes_and_files_panel_holds_only_the_modules_by_folder(): void
    {
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);
        $week2 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 2']);
        $labs = app(Folders::class)->create($by, 'module', $week1->id, 'Labs');
        $sql = app(Folders::class)->create($by, 'folder', $labs->id, 'SQL');
        app(Notes::class)->create($by, 'module', $week1->id, 'Lecture 1');
        app(Notes::class)->create($by, 'folder', $sql->id, 'Joins lab');
        app(Notes::class)->create($by, 'module', $week2->id, 'Week 2 reading');
        app(Notes::class)->create($by, 'workspace', $this->databases->id, 'Exam revision');
        $session = app(Sessions::class)->start($by, $this->databases->id, null, $week1->id);

        $this->page($session->id)->assertSee('Lecture 1')->assertSee('Joins lab')->assertDontSee('Week 2 reading')
            ->call('openMaterial')
            ->assertSeeInOrder(['In Week 1', 'Lecture 1', 'Labs', 'SQL', 'Joins lab'])
            ->assertDontSee('Week 2 reading')->assertDontSee('Exam revision');
    }

    public function test_a_note_written_from_a_session_goes_into_its_module_and_opens(): void
    {
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);
        $session = app(Sessions::class)->start($by, $this->databases->id, null, $week1->id);

        // The editor for a new note in the module: nothing is made until something is written.
        $this->page($session->id)->assertSee('Topics in Week 1')->call('newNote')
            ->assertRedirect(route('workspaces.notes.create', [$this->databases->id, 'in' => "module:{$week1->id}"]));
        $this->assertSame([], app(Notes::class)->list($by, $this->databases->id));
    }

    public function test_the_student_chooses_the_sessions_topic_or_makes_one_in_its_module(): void
    {
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);
        $keys = app(Topics::class)->create($by, $this->databases->id, 'Keys', $week1->id);
        $other = app(Topics::class)->create($by, $this->databases->id, 'Normal forms');
        $session = app(Sessions::class)->start($by, $this->databases->id, null, $week1->id);

        $page = $this->page($session->id)->assertSee('Choose a topic')
            ->call('editTopic')->assertSet('mode', 'topic')->assertSee('What this session is about')
            ->assertSeeInOrder(['Week 1', 'Keys', 'Other topics', 'Normal forms', 'A new topic'])
            ->set('topicChoice', $keys->id)->call('save')->assertSet('mode', null)->assertDispatched('session-changed')
            // The rail marks it as the one the session is on, and the header's topic names it.
            ->assertSeeInOrder(['Keys', 'Studying this now']);
        $this->assertSame($keys->id, $this->current()->topicId);

        // A new one goes in the session's module; a name the course has is that topic.
        $page->call('editTopic')->assertSet('topicChoice', $keys->id)->set('topicChoice', 'new')->assertSee('Added to Week 1.')
            ->call('save')->assertHasErrors('newTopic')
            ->set('newTopic', 'Candidate keys')->call('save')->assertSet('mode', null);
        $made = collect(app(Topics::class)->list($by, $this->databases->id))->firstWhere('name', 'Candidate keys');
        $this->assertSame([$made->id, $week1->id], [$this->current()->topicId, $made->moduleId]);
        $page->call('editTopic')->set('topicChoice', 'new')->set('newTopic', 'normal FORMS')->call('save');
        $this->assertSame($other->id, $this->current()->topicId);
        $page->call('editTopic')->set('topicChoice', '')->call('save')->assertSee('Choose a topic');
        $this->assertNull($this->current()->topicId);
        $this->assertCount(3, app(Topics::class)->list($by, $this->databases->id));

        // A card from the session goes in its module.
        $page->call('newFlashcard')->assertDispatched('flashcard-new', topicId: null, moduleId: $week1->id);
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
        $this->livewire(StudyTime::class, ['stats' => true])
            ->call('logTime')->assertSet('date', '2026-10-05')->assertSet('time', '08:00')
            ->set('minutes', '0')->call('save')->assertHasErrors('minutes')
            ->set('date', '2026-10-04')->set('time', '14:00')->set('minutes', '90')->call('save')
            ->assertSee('1 h 30 min logged.')->assertDispatched('session-changed')
            // Yesterday counts: the streak is alive until today ends.
            ->assertSeeInOrder(['Streak', '1 day', 'Last 7 days', '1 h 30 min', 'Sunday: 1 h 30 min', 'Monday: 0 min']);
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

        // A second one isn't started: the start panel says one is open.
        $this->livewire(Progress::class)->call('study', $joins->id)->assertDispatched('study-start', topicId: $joins->id)->assertNoRedirect();
        $this->assertCount(1, app(Sessions::class)->list($this->principal($this->ada), $this->databases->id));
    }

    public function test_starting_while_a_session_is_open_offers_to_go_back_to_it_or_end_it_first(): void
    {
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);
        $open = app(Sessions::class)->start($by, $this->databases->id, null, $week1->id);
        $this->travel(20)->minutes();

        $panel = $this->livewire(StudyTime::class)->call('newSession', $week1->id)
            ->assertSet('mode', 'busy')->assertDispatched('study-dialog-open')
            ->assertSee('You&#039;re already studying', false)->assertSeeInOrder(['Week 1', 'Studying', '20 min studied', 'End it', 'Go to the session'])
            ->assertSee(route('workspaces.sessions.show', [$this->databases->id, $open->id]), false)
            ->assertDontSee('Topic (optional)');
        // Start can't be forced past it.
        $panel->call('save');
        $this->assertSame($open->id, $this->current()->id);

        $panel->call('endOpen')->assertSet('mode', 'start')->assertSet('moduleId', $week1->id)
            ->assertSee('Session ended. You studied 20 min.')->assertSee('Topic (optional)');
        $this->assertNull($this->current());
    }

    public function test_a_session_in_a_module_sits_under_modules(): void
    {
        $by = $this->principal($this->ada);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);
        $session = app(Sessions::class)->start($by, $this->databases->id, null, $week1->id);

        $page = $this->actingAs($this->ada)->get(route('workspaces.sessions.show', [$this->databases->id, $session->id]))
            ->assertOk()->assertSeeInOrder(['Modules', 'Week 1', 'Whole module'])
            ->assertSee('Hide the timer')->assertSee('Show the study timer');
        $this->assertMatchesRegularExpression('/title="Modules"\s+aria-current="page"/', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('/title="Home"\s+aria-current="page"/', $page->getContent());
    }

    public function test_a_pomodoro_session_is_started_counts_down_and_moves_through_its_phases(): void
    {
        $this->livewire(StudyTime::class)
            ->call('newSession')->assertSet('clock', 'free')
            ->set('clock', 'pomodoro')->assertSee('Rhythm')->assertSee('Classic: 25 min focus, 5 min break, 15 min after 4')
            ->set('preset', 'custom')->set('focus', '200')->call('save')->assertHasErrors('focus')
            ->set('preset', 'classic')->set('auto', false)->call('save');
        $session = $this->current();
        $this->assertEquals(['focus' => 25, 'short' => 5, 'long' => 15, 'every' => 4, 'auto' => false], $session->pomodoro);

        $page = $this->page($session->id)
            ->assertSeeInOrder(['Focus 1 of 4', '25:00', '0 pomodoros', 'Pause', 'Skip to break', 'End session', 'Sound'])
            ->assertSee('data-countdown', false);

        $this->travel(10)->minutes();
        $page->call('skip')->assertSeeInOrder(['Short break', '05:00', 'Skip break']);
        $this->travel(6)->minutes();
        $page->call('phaseEnded')->assertSee('Ready for pomodoro 1')->assertSee('Start pomodoro 1')
            ->call('resume')->assertSee('Focus 1 of 4');

        // The next time, the dialog starts on the same clock.
        $page->call('confirmEnd')->call('save');
        $this->livewire(StudyTime::class)->call('newSession')->assertSet('clock', 'pomodoro')->assertSet('preset', 'classic')->assertSet('auto', false);

        $this->actingAs($this->ada)->get(route('home'))->assertDontSee('data-countdown', false);
    }

    public function test_the_clock_is_switched_to_pomodoro_during_a_session_and_the_top_bar_counts_down(): void
    {
        $session = app(Sessions::class)->start($this->principal($this->ada), $this->databases->id);

        $this->page($session->id)->assertSee('Use the Pomodoro clock')
            ->call('editPomodoro')->assertSet('clock', 'pomodoro')->set('preset', 'deep')->call('save')
            ->assertSee('The Pomodoro clock is on.')->assertSeeInOrder(['Focus 1 of 2', '50:00']);

        $this->actingAs($this->ada)->get(route('home'))->assertSee('data-countdown', false)->assertSee('Focus 1 of 2, 50 min left');

        $this->page($session->id)->call('editPomodoro')->set('clock', 'free')->call('save')->assertSee('The free clock is on.')->assertDontSee('Focus 1 of 2');
    }

    public function test_a_session_started_by_mistake_is_deleted(): void
    {
        $session = app(Sessions::class)->start($this->principal($this->ada), $this->databases->id);

        $this->page($session->id)->call('confirmDelete')->assertSee('Delete this session?')
            ->call('save')->assertRedirect(route('workspaces.show', $this->databases->id));
        $this->assertNull($this->current());
    }

    public function test_a_session_timer_and_full_restart_can_be_triggered_from_the_screen(): void
    {
        $session = app(Sessions::class)->start($this->principal($this->ada), $this->databases->id);
        $this->travel(15)->minutes();
        $this->assertSame(900, $this->current()->studySeconds);

        // Reset timer
        $this->page($session->id)
            ->call('confirmResetTime')
            ->assertSet('mode', 'reset_time')
            ->assertSee('Reset timer?')
            ->call('save')
            ->assertSet('mode', null)
            ->assertDispatched('session-dialog-close')
            ->assertDispatched('session-changed');

        $this->assertSame(0, $this->current()->studySeconds);

        // Restart session
        $this->travel(10)->minutes();
        $this->page($session->id)
            ->call('confirmResetFull')
            ->assertSet('mode', 'reset_full')
            ->assertSee('Restart session?')
            ->call('save')
            ->assertSet('mode', null)
            ->assertDispatched('session-dialog-close')
            ->assertDispatched('session-changed')
            ->assertDispatched('chat-reload');

        $this->assertSame(0, $this->current()->studySeconds);
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

    public function test_student_can_tune_tutor_in_real_time(): void
    {
        $session = app(Sessions::class)->start($this->principal($this->ada), $this->databases->id);

        $page = $this->page($session->id)
            ->assertSee('AI Tutor')
            ->assertSee('My understanding')
            ->assertSee('Intermediate')
            ->assertSee('Beginner')
            ->assertSee('Master')
            ->assertSee('Teaching style')
            ->assertSee('Explain')
            ->assertSee('Analogies')
            ->assertSee('Check questions')
            ->assertSee('Balanced depth with real, applied examples.')
            ->assertSeeHtml('aria-pressed="true"')
            ->call('setTutoringChoice', 'quiz', 'easy')
            ->assertDispatched('session-changed')
            ->assertSee('Plain words, built up from first principles.');

        $reloaded = app(Sessions::class)->find($this->principal($this->ada), $session->id);
        $this->assertSame('easy', $reloaded->tutoring['quiz']);

        $page->call('setTutoringChoice', 'method', 'analogy')
            ->call('setTutoringChoice', 'check_ins', 'none')
            ->assertDispatched('session-changed');

        $reloaded = app(Sessions::class)->find($this->principal($this->ada), $session->id);
        $this->assertSame('analogy', $reloaded->tutoring['method']);
        $this->assertSame('none', $reloaded->tutoring['check_ins']);
    }

    public function test_custom_rules_note_editor_can_be_opened_and_saved(): void
    {
        $by = $this->principal($this->ada);
        $session = app(Sessions::class)->start($by, $this->databases->id);

        $this->page($session->id)
            ->call('editCustomRules')
            ->assertSet('mode', 'custom_rules')
            ->assertDispatched('session-dialog-open')
            ->assertSee('Custom Tutor Rules (Do & Don\'t)')
            ->assertSee('What to do')
            ->assertSee('What NOT to do')
            ->set('customDo', "1. Explain step by step\n2. Provide code snippets")
            ->set('customDont', "1. Never give raw answers\n2. No LaTeX")
            ->call('save')
            ->assertDispatched('session-changed');

        $updated = app(Sessions::class)->find($by, $session->id);
        $this->assertSame("1. Explain step by step\n2. Provide code snippets", $updated->tutoring['custom_do']);
        $this->assertSame("1. Never give raw answers\n2. No LaTeX", $updated->tutoring['custom_dont']);
    }

    public function test_file_can_be_deleted_permanently_from_study_session_material_list(): void
    {
        $by = $this->principal($this->ada);
        $session = app(Sessions::class)->start($by, $this->databases->id);
        $file = app(Files::class)->upload($by, 'workspace', $this->databases->id, $this->temp($this->pdf()), 'Test_Diagram.pdf');
        app(Sessions::class)->toggleMaterial($by, $session->id, 'file:'.$file->id);

        $this->page($session->id)
            ->call('deleteMaterialFile', $file->id)
            ->assertDispatched('session-file-deleted', fileId: $file->id);

        $this->assertThrows(fn () => app(Files::class)->find($by, $file->id), NotFound::class);
        $this->assertFalse(app(Sessions::class)->find($by, $session->id)->uses('file:'.$file->id));
    }

    public function test_note_can_be_moved_to_trash_from_study_session_material_list(): void
    {
        $by = $this->principal($this->ada);
        $session = app(Sessions::class)->start($by, $this->databases->id);
        $note = app(Notes::class)->create($by, 'workspace', $this->databases->id, 'Lecture Summary');
        app(Sessions::class)->toggleMaterial($by, $session->id, 'note:'.$note->id);

        $this->page($session->id)
            ->call('deleteMaterialNote', $note->id)
            ->assertDispatched('session-note-deleted', noteId: $note->id);

        $this->assertNotNull(app(Notes::class)->find($by, $note->id)->trashedAt);
        $this->assertFalse(app(Sessions::class)->find($by, $session->id)->uses('note:'.$note->id));
    }

    public function test_deleting_from_the_session_asks_in_the_page_not_the_browser(): void
    {
        $by = $this->principal($this->ada);
        $session = app(Sessions::class)->start($by, $this->databases->id);

        $this->page($session->id)
            ->assertSeeHtml('id="session-confirm-dialog"')
            ->assertSee('Move this note to trash?')
            ->assertSee('Delete this file?')
            ->assertDontSeeHtml("confirm('");
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
