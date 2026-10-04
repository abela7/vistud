<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Livewire\Workspaces\SessionCapture;
use App\Livewire\Workspaces\TutorChat;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Modules;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The built-in chat on a study session's page (docs/specs/study-memory.md §6). */
class TutorChatScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private WorkspaceDetails $databases;

    private SessionDetails $session;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->databases = app(Workspaces::class)->create($this->by, ['name' => 'Databases']);
        $week2 = app(Modules::class)->create($this->by, $this->databases->id, ['title' => 'Week 2: SQL joins'])->id;
        $joins = app(Topics::class)->create($this->by, $this->databases->id, 'Joins', $week2)->id;
        $this->session = app(Sessions::class)->start($this->by, $this->databases->id, $joins, $week2);
        $this->actingAs($this->ada);
    }

    private function chat()
    {
        return Livewire::test(TutorChat::class, ['workspaceId' => $this->databases->id, 'sessionId' => $this->session->id]);
    }

    public function test_the_chat_waits_for_the_set_up_then_offers_openings(): void
    {
        $this->get(route('workspaces.sessions.show', [$this->databases->id, $this->session->id]))->assertOk()->assertSee('Your tutor')->assertSee('Set the AI engine up first')->assertSee(route('engine.settings'))->assertSee('Another AI');
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->chat()->assertSee('Say hello, or ask about anything in this course.')->assertSee('Quiz me on what I should know by now.')->assertSee('fake/tutor · $0.00 of $2.00 this session')->assertDontSee('Keep what the tutor marked');
    }

    public function test_a_turn_shows_both_sides_the_look_ups_the_cost_and_the_marks_as_quotes_then_keeps_them(): void
    {
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->engine->will(Fake::calls('topics'), Fake::says("**Joins** still confuse you.\n\n<finding topic=\"Joins\">A left join keeps every row of the left table.</finding>", 12_000));

        $chat = $this->chat()->set('text', "Where did we stop?\nAnd what next?")->call('send')->assertSet('text', '')->assertSet('error', null)->assertDispatched('chat-turn')
            ->assertSee("Where did we stop?\nAnd what next?")->assertSee('<strong>Joins</strong> still confuse you.', false)
            ->assertSee('Key point · Joins', false)->assertSee('A left join keeps every row of the left table.')->assertDontSee('<finding', false)
            ->assertSee('Looked up: topics · $0.01')->assertSee('$0.01 of $2.00 this session')->assertSee('Keep what the tutor marked');

        // Keeping opens the write-back's review straight on the reply's marks.
        $chat->call('keep')->assertDispatched('capture-open');
        Livewire::test(SessionCapture::class, ['workspaceId' => $this->databases->id, 'sessionId' => $this->session->id])
            ->call('open', '<finding topic="Joins">A left join keeps every row of the left table.</finding>')->assertSet('step', 'review')->assertSee('A left join keeps every row of the left table.')->assertDispatched('capture-dialog-open');

        // A suggested opening is sent as it is; anything else sent through it is refused quietly.
        $this->engine->will(Fake::says('Let\'s go.'));
        $chat->call('say', TutorChat::SUGGESTIONS[2])->assertSee('Quiz me on what I should know by now.')->assertSee("Let's go.", false);
        $chat->call('say', 'drop everything')->assertDontSee('drop everything');
    }

    public function test_refusals_show_under_the_box_and_an_ended_session_only_reads(): void
    {
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'session_cap' => '0.01', 'consent' => true]);
        $this->engine->will(Fake::says('Hi.', 20_000));
        $chat = $this->chat()->set('text', 'Hi')->call('send')->assertSet('error', null);
        $chat->set('text', 'More')->call('send')->assertSet('text', 'More')->assertSee('reached its limit of $0.01');

        app(Sessions::class)->end($this->by, $this->session->id);
        $this->chat()->assertSee('Hi.')->assertSee('This session has ended')->assertDontSee('Write to your tutor');
    }
}
