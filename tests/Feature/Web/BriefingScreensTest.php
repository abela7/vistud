<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\StudySession;
use App\Livewire\Workspaces\StudyTime;
use App\Models\User;
use App\Study\ModuleDetails;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Tutoring;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Teaching choices, material and the briefing on screen (docs/specs/study-memory.md §4.3). */
class BriefingScreensTest extends TestCase
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
        $this->databases = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Databases']);
        $this->week1 = app(Modules::class)->create($this->principal($this->ada), $this->databases->id, ['title' => 'Week 1']);
    }

    public function test_teaching_is_chosen_when_a_session_starts_and_offered_again_next_time(): void
    {
        $this->livewire(StudyTime::class)
            ->call('newSession')->assertSee('How the AI teaches')->assertSee('Explain, then check · checks after every section · normal questions · one slide at a time')
            ->set('method', 'socratic')->set('quiz', 'exam')->assertSee('Socratic · checks after every section · exam level questions')
            ->set('pace', 'fast')->call('save')->assertHasErrors('pace')
            ->set('pace', 'section')->call('save');
        $session = $this->current();
        $this->assertSame(['method' => 'socratic', 'check_ins' => 'section', 'quiz' => 'exam', 'pace' => 'section'], $session->tutoring);

        app(Sessions::class)->end($this->principal($this->ada), $session->id);
        $this->livewire(StudyTime::class)->call('newSession')->assertSet('method', 'socratic')->assertSet('quiz', 'exam')->assertSet('pace', 'section');
    }

    public function test_the_session_page_shows_and_changes_how_the_ai_teaches(): void
    {
        $session = app(Sessions::class)->start($this->principal($this->ada), $this->databases->id);

        $this->page($session->id)
            ->assertSeeInOrder(['How the AI teaches', 'How to teach', 'Explain, then check', 'Check questions', 'After every section', 'Quiz level', 'Normal', 'Pace', 'One slide at a time'])
            ->call('editTeaching')->assertDispatched('session-dialog-open')->assertSet('method', 'explain')
            ->set('method', 'steps')->set('checkIns', 'none')->call('save')
            ->assertSee('The briefing now asks for this way of teaching.')
            ->assertSeeInOrder(['How to teach', 'Step by step', 'Check questions', 'None']);
        $this->assertSame('steps', $this->current()->tutoring['method']);
    }

    public function test_notes_and_files_go_into_the_briefing_from_the_material_card(): void
    {
        $by = $this->principal($this->ada);
        $joins = app(Topics::class)->create($by, $this->databases->id, 'Joins', $this->week1->id);
        $lecture = app(Notes::class)->create($by, 'module', $this->week1->id, 'Lecture 3');
        $revision = app(Notes::class)->create($by, 'workspace', $this->databases->id, 'Exam revision');
        $session = app(Sessions::class)->start($by, $this->databases->id, $joins->id);

        $page = $this->page($session->id)
            ->assertSeeInOrder(['Material · Week 1', 'Lecture 3', 'Use', 'Other notes and files (1)', 'Exam revision'])
            ->assertSee('aria-pressed="false"', false);

        $page->call('toggleMaterial', "note:{$lecture->id}")->call('toggleMaterial', "note:{$revision->id}");
        $this->assertSame(["note:{$lecture->id}", "note:{$revision->id}"], $this->current()->material);
        // A note chosen from elsewhere moves up with the module's material.
        $page->assertDontSee('Other notes and files')->assertSeeInOrder(['Lecture 3', 'Exam revision']);

        $page->call('toggleMaterial', 'note:not-mine')->assertSee('That note or file no longer exists.');
    }

    public function test_the_briefing_shows_what_the_ai_receives_and_downloads_as_markdown(): void
    {
        $by = $this->principal($this->ada);
        $joins = app(Topics::class)->create($by, $this->databases->id, 'Joins', $this->week1->id);
        $session = app(Sessions::class)->start($by, $this->databases->id, $joins->id);

        $this->page($session->id)->assertSee('Briefing')
            ->call('showBriefing')->assertDispatched('session-dialog-open')
            ->assertSee('Briefing for the AI')->assertSeeText('tokens.')
            ->assertSee('# You are the student&#039;s tutor', false)->assertSee('## This session')->assertSee('- Topic: Joins.')
            ->assertSee('Copy')->assertSee(route('workspaces.sessions.briefing', [$this->databases->id, $session->id]), false);

        $response = $this->actingAs($this->ada)->get(route('workspaces.sessions.briefing', [$this->databases->id, $session->id]));
        $response->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="vistud-briefing-joins-databases-2026-10-05.md"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('# You are the student\'s tutor', $response->getContent());
        $this->assertStringContainsString(Tutoring::CHOICES['method']['explain'][1], $response->getContent());
    }

    public function test_an_ended_session_keeps_its_material_but_cant_change_it(): void
    {
        $by = $this->principal($this->ada);
        $lecture = app(Notes::class)->create($by, 'module', $this->week1->id, 'Lecture 3');
        $session = app(Sessions::class)->start($by, $this->databases->id, null, $this->week1->id);
        app(Sessions::class)->toggleMaterial($by, $session->id, "note:{$lecture->id}");
        app(Sessions::class)->end($by, $session->id);

        $this->page($session->id)->assertSeeInOrder(['Lecture 3', 'Used'])->assertDontSee('toggleMaterial', false)->assertDontSee('Change how the AI teaches');
        $this->assertThrows(fn () => app(Sessions::class)->toggleMaterial($by, $session->id, "note:{$lecture->id}"));
    }

    public function test_another_students_briefing_is_missing(): void
    {
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);
        $session = app(Sessions::class)->start($this->principal($bob), $theirs->id);
        $mine = app(Sessions::class)->start($this->principal($this->ada), $this->databases->id);
        $maths = app(Workspaces::class)->create($this->principal($this->ada), ['name' => 'Maths']);

        $this->actingAs($this->ada)->get(route('workspaces.sessions.briefing', [$theirs->id, $session->id]))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.sessions.briefing', [$this->databases->id, $session->id]))->assertNotFound();
        $this->actingAs($this->ada)->get(route('workspaces.sessions.briefing', [$maths->id, $mine->id]))->assertNotFound();
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

        return Livewire::test($class, ['workspaceId' => $this->databases->id] + $params);
    }
}
