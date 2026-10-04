<?php

namespace Tests\Feature\Web;

use App\Engine\Settings;
use App\Livewire\Workspaces\SessionCapture;
use App\Livewire\Workspaces\StudySession;
use App\Models\User;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\TopicDetails;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Save from the chat, on a session's page (docs/specs/study-memory.md §4.4). */
class SessionCaptureScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private const CHAT = <<<'CHAT'
        <finding topic="Joins">A LEFT JOIN keeps every row of the left table.</finding>
        <question topic="Joins">Why are unmatched columns NULL?</question>
        <flashcard topic="Joins"><front>What does a LEFT JOIN keep?</front><back>Every left row.</back></flashcard>
        <attempt topic="Keys" form="recall" result="partial"><asked>What makes a foreign key?</asked><answer>A pointer column.</answer></attempt>
        <checkpoint>Slide 7 of 12; next is self joins.</checkpoint>
        <summary>We covered left joins; NULLs were confusing at first.</summary>
        <status topic="Joins" proposed="understood">Answered well.</status>
        CHAT;

    private User $ada;

    private WorkspaceDetails $databases;

    private TopicDetails $joins;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $week1 = app(Modules::class)->create($by, $this->databases->id, ['title' => 'Week 1']);
        $this->joins = app(Topics::class)->create($by, $this->databases->id, 'Joins', $week1->id);
    }

    public function test_the_tutors_marks_are_pasted_reviewed_and_saved(): void
    {
        $session = app(Sessions::class)->start($this->principal($this->ada), $this->databases->id, $this->joins->id);

        // Pasting a chat from another AI is for the student who says they use one: off until then.
        $this->actingAs($this->ada)->get(route('workspaces.sessions.show', [$this->databases->id, $session->id]))->assertOk()->assertDontSee('Save from another AI');
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->principal($this->ada), ['tutor_model' => 'fake/tutor', 'copy_paste_ai' => true, 'consent' => true]);
        $this->actingAs($this->ada)->get(route('workspaces.sessions.show', [$this->databases->id, $session->id]))->assertOk()->assertSee('Save from another AI');

        $capture = $this->capture($session->id)
            ->call('open')->assertDispatched('capture-dialog-open')->assertSee("The tutor's replies", false)
            ->call('read')->assertHasErrors('text')
            ->set('text', 'Nice chat, no marks.')->call('read')->assertHasErrors('text')
            ->set('text', self::CHAT)->call('read')->assertSet('step', 'review')
            ->assertSeeInOrder(['The tutor&#039;s summary', 'Key points', 'Questions', 'Flashcards', 'Your answers', 'Partly right', 'What makes a foreign key?', 'New topic: Keys', 'Where the session stands', 'Statuses: you decide', 'Set Joins to understood'], false)
            ->assertSee('Save 6');

        $status = collect($capture->get('items'))->search(fn ($item) => $item['kind'] === 'status');
        $capture->set("items.{$status}.include", true)->assertSee('Save 7')
            ->call('save')->assertDispatched('capture-dialog-close')->assertDispatched('session-changed')
            ->assertSee('Saved 1 key point, 1 question, 1 flashcard, 1 answer, 1 status, the summary and where the session stands.');

        $this->assertSame('understood', app(Topics::class)->find($this->principal($this->ada), $this->joins->id)->status);
        // Once it has ended, the page keeps what the tutor wrote for the next session.
        app(Sessions::class)->end($this->principal($this->ada), $session->id);
        $this->page($session->id)->assertSeeInOrder(['From the tutor', 'Summary', 'We covered left joins; NULLs were confusing at first.', 'Where it stands', 'Slide 7 of 12; next is self joins.']);

        // Pasting the same chat again: what was saved is recognised.
        $this->capture($session->id)->call('open')->set('text', self::CHAT)->call('read')->assertSee('Saved before')->assertSee('Nothing ticked');
    }

    public function test_what_couldnt_be_saved_is_listed(): void
    {
        $session = app(Sessions::class)->start($this->principal($this->ada), $this->databases->id, $this->joins->id);
        $capture = $this->capture($session->id)->call('open')->set('text', self::CHAT)->call('read');
        $finding = collect($capture->get('items'))->search(fn ($item) => $item['kind'] === 'finding');

        $capture->set("items.{$finding}.text", '')->call('save')
            ->assertSee('Some things weren&#039;t saved', false)->assertSee('Write what you need to know.');
    }

    public function test_the_browser_cannot_change_which_session_it_saves_to(): void
    {
        $session = app(Sessions::class)->start($this->principal($this->ada), $this->databases->id);
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Private']);
        $other = app(Sessions::class)->start($this->principal($bob), $theirs->id);

        $this->assertThrows(fn () => $this->capture($session->id)->set('sessionId', $other->id), CannotUpdateLockedPropertyException::class);
        $this->assertThrows(fn () => $this->capture($other->id)->call('open')->set('text', self::CHAT)->call('read'));
    }

    private function page(string $sessionId)
    {
        return $this->livewire(StudySession::class, ['sessionId' => $sessionId]);
    }

    private function capture(string $sessionId)
    {
        return $this->livewire(SessionCapture::class, ['sessionId' => $sessionId]);
    }

    private function livewire(string $class, array $params = [])
    {
        $this->actingAs($this->ada);
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return Livewire::test($class, ['workspaceId' => $this->databases->id] + $params);
    }
}
