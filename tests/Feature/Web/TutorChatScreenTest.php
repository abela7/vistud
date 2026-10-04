<?php

namespace Tests\Feature\Web;

use App\Engine\ChatMarks;
use App\Engine\Engine;
use App\Engine\EngineFailed;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Livewire\Workspaces\ChatStream;
use App\Livewire\Workspaces\SessionCapture;
use App\Livewire\Workspaces\StudySession;
use App\Livewire\Workspaces\TutorChat;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Files;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\SessionDetails;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The built-in chat on a study session's page (docs/specs/study-memory.md §6). */
class TutorChatScreenTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

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
            ->assertSee('Looked up: your topics · $0.01')->assertSee('$0.01 of $2.00 this session')->assertSee('Keep what the tutor marked');

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

    public function test_ending_the_session_from_its_page_saves_the_chats_summary_for_the_next_one(): void
    {
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'quick_model' => 'fake/quick', 'consent' => true]);
        $this->engine->will(Fake::says('A left join keeps every left row.'));
        $this->chat()->set('text', 'What is a left join?')->call('send');

        $this->engine->will(Fake::says('{"summary": "Left joins, explained and understood.", "checkpoint": "Right joins next."}', 200, 'fake/quick'));
        Livewire::test(StudySession::class, ['workspaceId' => $this->databases->id, 'sessionId' => $this->session->id])
            ->call('confirmEnd')->assertSee('saved with the session')
            ->call('save')
            ->assertSee('summary of the chat is saved below')
            ->assertSeeInOrder(['From the tutor', 'Left joins, explained and understood.', 'Right joins next.']);
        $this->assertSame('Left joins, explained and understood.', app(Sessions::class)->find($this->by, $this->session->id)->summary);

        // Opened again later, the ended session's page asks the engine nothing more.
        $asked = count($this->engine->requests);
        $this->chat()->assertDontSee('Write to your tutor');
        $this->assertCount($asked, $this->engine->requests);
    }

    public function test_the_answer_streams_in_with_what_the_tutor_looks_up_and_half_written_marks_held_back(): void
    {
        $stream = new class extends ChatStream
        {
            public array $pushed = [];

            public function push(Component $component, string $target, string $html): void
            {
                $this->pushed[] = [$target, $html];
            }
        };
        $this->app->instance(ChatStream::class, $stream);
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->engine->will(Fake::calls('questions'), Fake::says("**Joins** first.\n\n<finding topic=\"Joins\">A left join keeps every left row.</finding>"));

        $this->chat()->call('send', 'What next?')->assertDispatched('chat-turn')->assertDispatched('chat-done')
            ->assertSee('What next?')->assertSee('Key point · Joins', false);

        $this->assertSame(['status', 'Looking up your questions…'], $stream->pushed[0]);
        $this->assertSame(['status', ''], $stream->pushed[1]);
        $answers = array_column(array_filter($stream->pushed, fn ($p) => $p[0] === 'answer'), 1);
        $this->assertNotEmpty($answers);
        $this->assertStringContainsString('<strong>Joins</strong>', $answers[0]);
        foreach ($answers as $html) {
            $this->assertStringNotContainsString('<finding', $html);
            $this->assertStringNotContainsString('&lt;finding', $html);
        }

        // Half-written marks and tags are held back until they close.
        $this->assertSame('Hello ', ChatMarks::partial('Hello <finding topic="Joins">A left'));
        $this->assertSame('Hello ', ChatMarks::partial('Hello <flash'));
        $this->assertSame('Done <finding topic="J">x</finding> and', ChatMarks::partial('Done <finding topic="J">x</finding> and'));
    }

    public function test_a_failed_answer_offers_to_try_again_and_refused_words_go_back_to_the_box(): void
    {
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'session_cap' => '0.01', 'consent' => true]);
        $this->engine->will(fn () => throw new EngineFailed('engine_busy', 'The engine is busy right now.'));
        $chat = $this->chat()->call('send', 'What is a join?')
            ->assertSee('The engine is busy right now.')->assertSee('What is a join?')
            ->assertSee("The tutor hasn't answered your last message.", false)->assertSee('Try again')
            ->assertDispatched('chat-done', restore: null);

        $this->engine->will(Fake::says('A join combines rows.', 20_000));
        $chat->call('retry')->assertSet('error', null)->assertSee('A join combines rows.')->assertDontSee('Try again')->assertDispatched('chat-turn');

        // Over the limit: nothing is kept, so the words go back to the box.
        $chat->call('send', 'And a left join?')->assertSee('reached its limit of $0.01')->assertDispatched('chat-done', restore: 'And a left join?')->assertDontSee('Try again');
    }

    public function test_the_modules_notes_and_files_can_be_attached_and_show_on_the_message(): void
    {
        Storage::fake('local');
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $week2 = $this->session->moduleId;
        $note = app(Notes::class)->create($this->by, 'module', $week2, 'Lecture 3: joins');
        $deck = app(Files::class)->upload($this->by, 'module', $week2, $this->temp($this->pdf()), 'Joins deck.pdf');
        $elsewhere = app(Modules::class)->create($this->by, $this->databases->id, ['title' => 'Week 9'])->id;
        app(Notes::class)->create($this->by, 'module', $elsewhere, 'Week 9 recap');
        app(Sessions::class)->toggleMaterial($this->by, $this->session->id, "file:{$deck->id}");

        // The session's material first, marked; the module's notes next; another module's not at all.
        $chat = $this->chat()->assertSeeInOrder(['Attach a note, a file or a picture', 'Joins deck.pdf', 'This session', 'Lecture 3: joins'])->assertDontSee('Week 9 recap')
            ->assertSee('Upload a file or picture')->assertSee('paste a screenshot');

        $this->engine->will(Fake::says('Slide one is about joins.'));
        $chat->call('send', 'What is on the first page?', ["file:{$deck->id}", "note:{$note->id}"])->assertSet('error', null)
            ->assertSee(route('workspaces.files.show', [$this->databases->id, $deck->id]), false)
            ->assertSee(route('workspaces.notes.show', [$this->databases->id, $note->id]), false)
            ->assertSeeInOrder(['What is on the first page?', 'Joins deck.pdf', 'Lecture 3: joins', 'Slide one is about joins.']);

        // A refusal says why, and gives the words back.
        $chat->call('send', 'And this?', ['topic:nope'])->assertSee('That isn&#039;t a note or a file.', false)->assertDispatched('chat-done', restore: 'And this?');
    }

    public function test_quiz_me_offers_the_topic_the_module_and_what_is_hardest(): void
    {
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $chat = $this->chat()->assertSeeInOrder(['Quiz me', 'On Joins', 'On Week 2: SQL joins', 'On what I find hardest']);

        $this->engine->will(Fake::says('**Question 1 of 5** What does a left join keep?'));
        $chat->call('say', 'Quiz me on Joins.')->assertSee('Quiz me on Joins.')->assertSee('Question 1 of 5');
        $this->assertSame('Quiz me on Joins.', $this->engine->last()->messages[0]['content']);

        // Only what the menu offers can be sent this way.
        $chat->call('say', 'Quiz me on everything else.')->assertDontSee('Quiz me on everything else.');
        // Once the session has ended, there is no quiz to start.
        app(Sessions::class)->end($this->by, $this->session->id);
        $this->chat()->assertDontSee('On what I find hardest');
    }

    public function test_an_answer_shows_what_was_saved_and_links_the_note_written_in_and_open_notes_are_told(): void
    {
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->engine->will(
            Fake::calls('make_flashcards', ['cards' => [['front' => 'What is a join?', 'back' => 'Rows combined.']]], 'call_1'),
            Fake::calls('write_note', ['text' => 'Joins combine rows.'], 'call_2'),
            Fake::says('Saved a card and noted it.'),
        );

        $chat = $this->chat()->call('send', 'Card and note please')
            ->assertSee('Saved 1 flashcard')->assertSee('Wrote in Study notes · Joins')
            ->assertDispatched('notes-changed')->assertDispatched('questions-changed');
        $note = collect(app(Notes::class)->list($this->by, $this->databases->id))->first(fn ($n) => str_starts_with($n->title, 'Study notes'));
        $chat->assertSee(route('workspaces.notes.show', [$this->databases->id, $note->id, 'window' => 1]), false);
        $this->assertSame(1, count(app(Flashcards::class)->list($this->by, $this->databases->id)));
    }

    public function test_a_topic_the_tutor_sets_shows_on_the_answer_and_the_session_page_is_told(): void
    {
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->engine->will(
            Fake::calls('set_topic', ['topic' => 'Outer joins'], 'call_1'),
            Fake::says('We are on outer joins now.'),
        );

        $this->chat()->call('send', 'Let us do outer joins')
            ->assertSee('Topic: Outer joins')->assertSee('Saved 1 new topic')->assertDispatched('session-changed');
        $this->assertSame('Outer joins', app(Topics::class)->find($this->by, app(Sessions::class)->find($this->by, $this->session->id)->topicId)->name);
    }
}
