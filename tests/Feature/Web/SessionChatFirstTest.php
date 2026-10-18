<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\SessionChat;
use App\Engine\Settings;
use App\Livewire\Workspaces\StudySession;
use App\Livewire\Workspaces\TutorChat;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Findings;
use App\Study\Flashcards;
use App\Study\Instructions;
use App\Study\Modules;
use App\Study\Questions;
use App\Study\Quizzes;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The session as a conversation (docs/specs/vistud-2-blueprint.md §3.5.4, §3.9): modes, chips, the rail, statuses the tutor sets and the end screen. */
class SessionChatFirstTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private Fake $engine;

    private string $workspace;

    private string $module;

    private string $joins;

    private string $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspace = app(Workspaces::class)->create($this->by, ['name' => 'Databases'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->workspace, ['title' => 'Week 2: SQL joins'])->id;
        $this->joins = app(Topics::class)->create($this->by, $this->workspace, 'Joins', $this->module)->id;
        $this->keys = app(Topics::class)->create($this->by, $this->workspace, 'Keys', $this->module)->id;
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true]);
        $this->actingAs($this->ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
    }

    private function start(?string $topic = null, ?string $module = null, ?string $mode = null)
    {
        return app(Sessions::class)->start($this->by, $this->workspace, $topic, $module, null, null, $mode);
    }

    private function chat(string $session)
    {
        return Livewire::test(TutorChat::class, ['workspaceId' => $this->workspace, 'sessionId' => $session]);
    }

    private function page(string $session)
    {
        return Livewire::test(StudySession::class, ['workspaceId' => $this->workspace, 'sessionId' => $session]);
    }

    public function test_the_header_names_the_mode_and_a_quiz_or_test_waits_for_the_student_to_send_it(): void
    {
        $topic = $this->start($this->joins);
        $this->page($topic->id)->assertSee('Joins')->assertSee('Week 2: SQL joins');
        $this->chat($topic->id)->assertSet('text', '');
        app(Sessions::class)->end($this->by, $topic->id);

        $whole = $this->start(null, $this->module);
        $this->page($whole->id)->assertSee('Whole module');
        app(Sessions::class)->end($this->by, $whole->id);

        // A quiz: the ask is in the box, not sent; the tutor says nothing until the student does.
        $quiz = $this->start($this->joins, null, 'quiz');
        $this->page($quiz->id)->assertSee('Quiz · Joins');
        $this->chat($quiz->id)->assertSet('text', 'Quiz me on Joins.');
        $this->assertSame(0, count($this->engine->requests));
        app(Sessions::class)->end($this->by, $quiz->id);

        $test = $this->start(null, $this->module, 'test');
        $this->page($test->id)->assertSee('Test · Week 2: SQL joins');
        $this->chat($test->id)->assertSet('text', TutorChat::TEST_ASK);
        // Once the chat has begun, nothing is put in the box again.
        $this->engine->will(Fake::says('First question: what does a join do?'));
        $chat = $this->chat($test->id)->call('send')->assertSet('text', '');
        $this->chat($test->id)->assertSet('text', '');
        $chat->call('say', TutorChat::TEST_ASK);
        app(Sessions::class)->end($this->by, $test->id);

        $free = $this->start(null, null, 'free');
        $this->page($free->id)->assertSee('Free study');
        app(Sessions::class)->end($this->by, $free->id);
        $bare = $this->start();
        $this->page($bare->id)->assertSee('Study session');
    }

    public function test_the_chips_ask_the_tutor_and_the_menu_holds_the_manual_tools(): void
    {
        $session = $this->start($this->joins);
        $chat = $this->chat($session->id)->assertSee('Quiz me')->assertSee('Cards')->assertSee('Note this')->assertSee('Where are we')
            ->assertSee('Test me on the module')->assertSee('Attach a note, file or picture')->assertSee('Ask a question')->assertSee('New flashcard')->assertSee('Write a note')
            ->assertSee('fake/tutor · $0.00 of $2.00 this session');

        // A chip sends its words; anything else is refused quietly.
        $this->engine->will(Fake::says('Here you go.'));
        $chat->call('say', TutorChat::ACTIONS['cards'][2])->assertSee('Make flashcards of what we just covered.')->assertSee('Here you go.');
        $chat->call('say', 'Delete everything.')->assertDontSee('Delete everything.');

        // Plotting a graph is offered only to a subject that has graphs: with the Math & Physics plugin on.
        $chat->assertDontSee('Plot graph');
        $chat->call('togglePlugin', 'math_physics')->assertSee('Plot graph');
        $chat->call('togglePlugin', 'math_physics')->assertDontSee('Plot graph');

        // An ended session only reads: no chips, no box.
        app(Sessions::class)->end($this->by, $session->id);
        $this->chat($session->id)->assertDontSee('Quiz me')->assertDontSee('Write to your tutor');
    }

    public function test_the_rail_switches_the_topic_and_says_what_the_session_saved(): void
    {
        $session = $this->start($this->joins);
        $this->engine->will(Fake::calls('make_flashcards', ['cards' => [['front' => 'A?', 'back' => 'B.'], ['front' => 'C?', 'back' => 'D.']]], 'c1'), Fake::calls('save_key_points', ['points' => [['text' => 'An outer join keeps unmatched rows.']]], 'c2'), Fake::says('Saved.'));
        app(SessionChat::class)->send($this->by, $session->id, 'Make cards and keep a point.');

        $page = $this->page($session->id)->assertSeeInOrder(['Topics in Week 2: SQL joins', 'Joins', 'this session is on it', 'Keys', 'This session', '2 flashcards, 1 key point'])
            ->assertSeeHtml('wire:click="clearTopic"');
        $this->assertSame($this->joins, app(Sessions::class)->find($this->by, $session->id)->topicId);

        // What it says the session saved is what is still there: a card and the key point deleted, and it counts less.
        $page->call('deleteCard', app(Flashcards::class)->list($this->by, $this->workspace)[0]->id)->assertSee('1 flashcard, 1 key point');
        app(Findings::class)->delete($this->by, app(Findings::class)->byTopic($this->by, $this->workspace)[$this->joins][0]->id);
        $this->assertSame(['flashcard' => 1], app(SessionChat::class)->activity($this->by, $session->id)['saved']);
        // The chips under the tutor's replies say the same: what is still there, not what was once saved.
        $reply = array_values(array_filter(app(SessionChat::class)->transcript($this->by, $session->id), fn ($turn) => $turn['role'] === 'assistant'))[0];
        $this->assertSame(['flashcard' => 1], $reply['saved']);

        // Tapping a topic makes the session about it, and the chat shows where; a topic that isn't there (or isn't
        // theirs) is only said so.
        $this->travel(1)->minutes();
        $page->call('switchTopic', $this->keys)->assertSee('Now on Keys.')->assertDispatched('session-changed')->assertDispatched('session-topic-changed');
        $this->assertSame($this->keys, app(Sessions::class)->find($this->by, $session->id)->topicId);
        $this->chat($session->id)->assertSee('You moved the session to Keys');
        $page->call('switchTopic', 'not-a-topic')->assertSee('That topic no longer exists.');
        $other = $this->principal($this->student());
        $theirs = app(Topics::class)->create($other, app(Workspaces::class)->create($other, ['name' => 'Theirs'])->id, 'Secret');
        $page->call('switchTopic', $theirs->id)->assertSee('That topic no longer exists.');
        $this->assertSame($this->keys, app(Sessions::class)->find($this->by, $session->id)->topicId);

        // The ticked topic, tapped again: the session has no topic, and no row is ticked.
        $page->call('clearTopic')->assertSee('The session has no topic now.')->assertDispatched('session-changed')
            ->assertDispatched('session-topic-changed')->assertDontSeeHtml('wire:click="clearTopic"');
        $this->assertNull(app(Sessions::class)->find($this->by, $session->id)->topicId);
        $this->chat($session->id)->assertSee('You took the topic off the session')->assertDontSee('You moved the session to Keys');
    }

    public function test_the_rail_counts_the_quizzes_that_are_still_there_and_one_can_be_deleted_from_the_session(): void
    {
        $session = $this->start($this->joins);
        $this->engine->will(Fake::calls('record_quiz', ['kind' => 'quiz', 'questions' => [['asked' => 'What is a key?', 'answer' => 'A column.', 'result' => 'correct']]], 'c1'), Fake::says('Recorded.'));
        app(SessionChat::class)->send($this->by, $session->id, 'Quiz me.');
        $this->assertSame(1, app(SessionChat::class)->activity($this->by, $session->id)['quizzes']);

        $quiz = app(Quizzes::class)->forSession($this->by, $session->id)[0];
        $this->page($session->id)->call('deleteQuiz', $quiz->id)->assertSee('Quiz deleted.')->assertDispatched('quizzes-changed')
            ->call('deleteQuiz', $quiz->id)->assertHasNoErrors();
        $this->assertSame([], app(Quizzes::class)->forSession($this->by, $session->id));
        $this->assertSame(0, app(SessionChat::class)->activity($this->by, $session->id)['quizzes']);
    }

    public function test_the_tutors_status_is_a_chip_that_can_be_undone_and_a_suggestion_a_chip_that_can_be_accepted(): void
    {
        $session = $this->start($this->joins);
        $topics = app(Topics::class);
        $this->engine->will(Fake::calls('set_topic_status', ['status' => 'understood', 'reason' => 'Two right answers.'], 'c1'), Fake::says('You have this.'));
        $chat = $this->chat($session->id)->set('text', 'I think I get joins.')->call('send')
            ->assertSee('Joins: understood, marked by the tutor')->assertSee('Undo');
        $this->assertSame(['understood', 'tutor'], [$topics->find($this->by, $this->joins)->status, $topics->find($this->by, $this->joins)->statusBy]);

        // Undo puts it back as it was; the chip says so and no longer offers it.
        $chat->call('undoStatus', $this->joins)->assertDispatched('session-changed')->assertSee('Joins: understood, undone')->assertDontSee('marked by the tutor');
        $this->assertNull($topics->find($this->by, $this->joins)->status);
        // Nothing to undo twice, and nothing of another's.
        $chat->call('undoStatus', $this->joins)->call('undoStatus', 'not-a-topic');

        // With "Let the tutor mark topics" off, it is only a suggestion with a tap.
        app(Settings::class)->set($this->by, ['tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'consent' => true, 'tutor_marks_topics' => false]);
        $this->engine->will(Fake::calls('set_topic_status', ['topic' => 'Keys', 'status' => 'confusing', 'reason' => 'Mixed up two keys.'], 'c2'), Fake::says('Keys are worth another look.'));
        $chat->set('text', 'Next.')->call('send')->assertSee('Keys: not understood?')->assertSee('Mark it');
        $this->assertNull($topics->find($this->by, $this->keys)->status);
        $chat->call('applyStatus', $this->keys, 'confused')->assertSee('Keys: not understood')->assertDontSee('Mark it');
        $mine = $topics->find($this->by, $this->keys);
        $this->assertSame(['confused', 'stated'], [$mine->status, $mine->statusBy]);
        // Only the three statuses can be applied.
        $chat->call('applyStatus', $this->joins, 'mastered');
        $this->assertNull($topics->find($this->by, $this->joins)->status);
    }

    public function test_a_quiz_the_tutor_records_is_shown_on_its_answer_and_a_test_proposes_statuses(): void
    {
        $session = $this->start($this->joins, null, 'test');
        $this->engine->will(
            Fake::calls('record_quiz', ['kind' => 'test', 'questions' => [
                ['asked' => 'What does a left join keep?', 'answer' => 'Left rows.', 'result' => 'correct', 'topic' => 'Joins'],
                ['asked' => 'What is a primary key?', 'answer' => 'A name.', 'result' => 'incorrect', 'topic' => 'Keys'],
                ['asked' => 'Can a key be null?', 'answer' => 'No.', 'result' => 'incorrect', 'topic' => 'Keys'],
            ]], 'c1'),
            Fake::says('You scored 33 %.'),
        );
        $this->chat($session->id)->set('text', 'That was the last one.')->call('send')
            ->assertSee('Test kept: 33 % over 3 questions')
            ->assertSee('Joins: understood, marked by the tutor')->assertSee('Keys: not understood, marked by the tutor');
        $this->assertSame(['understood', 'confused'], [app(Topics::class)->find($this->by, $this->joins)->status, app(Topics::class)->find($this->by, $this->keys)->status]);
        // The end screen offers both, starting on them.
        $this->page($session->id)->call('confirmEnd')->assertSet('statuses', [$this->joins => 'understood', $this->keys => 'confused']);
    }

    public function test_the_end_screen_starts_each_topic_on_what_the_tutor_gave_and_keeps_the_students_choices(): void
    {
        $topics = app(Topics::class);
        $topics->report($this->by, $this->keys, 'confused');
        $session = $this->start($this->joins);
        $this->engine->will(
            Fake::calls('set_topic_status', ['status' => 'understood', 'reason' => 'Right first time.'], 'c1'),
            Fake::calls('set_topic_status', ['topic' => 'Keys', 'status' => 'covered', 'reason' => 'We went over it.'], 'c2'),
            Fake::says('Both noted.'),
        );
        app(SessionChat::class)->send($this->by, $session->id, 'Wrap joins and keys.');
        // The student's own word on Keys stayed: the tutor could only suggest.
        $this->assertSame(['confused', 'stated'], [$topics->find($this->by, $this->keys)->status, $topics->find($this->by, $this->keys)->statusBy]);

        $page = $this->page($session->id)->call('confirmEnd')->assertSet('mode', 'end')
            ->assertSet('statuses', [$this->joins => 'understood', $this->keys => 'covered'])
            ->assertSeeInOrder(['Where you stand', 'Joins', 'The tutor marked it Understood: Right first time.', 'Keys', 'The tutor suggests Studying: We went over it.']);

        // The student keeps Joins as the tutor said and changes their mind about Keys; ending makes both their word.
        $page->set('statuses.'.$this->keys, '')->call('save')->assertSet('mode', 'done')->assertSee('Session ended');
        $joins = $topics->find($this->by, $this->joins);
        $this->assertSame(['understood', 'stated'], [$joins->status, $joins->statusBy]);
        $this->assertSame(['confused', 'stated'], [$topics->find($this->by, $this->keys)->status, $topics->find($this->by, $this->keys)->statusBy]);
        $this->assertSame('ended', app(Sessions::class)->find($this->by, $session->id)->state);
    }

    public function test_a_question_and_what_the_tutor_should_know_are_kept_from_the_pages_menu(): void
    {
        $session = $this->start($this->joins);
        $page = $this->page($session->id)->assertSee('Tell the tutor about this module')
            ->call('tellTutor')->assertSet('mode', 'tell')->assertSee('What should the tutor know about Week 2: SQL joins?')
            ->set('moduleNote', 'The exam covers joins only.')->call('save')->assertSet('mode', null)->assertSee('The tutor will use it from your next message.');
        $this->assertSame('The exam covers joins only.', app(Instructions::class)->get($this->by, "module:{$this->module}"));
        $page->call('tellTutor')->assertSet('moduleNote', 'The exam covers joins only.');

        $page->call('newQuestion')->set('questionText', 'Why is an inner join symmetric?')->call('save')->assertSet('mode', null)->assertDispatched('questions-changed');
        $asked = collect(app(Questions::class)->list($this->by, $this->workspace))->firstWhere('text', 'Why is an inner join symmetric?');
        $this->assertSame([$this->joins, $this->module, $session->id, 'pending'], [$asked->topicId, $asked->moduleId, $asked->sessionId, $asked->status]);

        $this->engine->will(Fake::says('An inner join keeps only rows that match on both sides, so the order of the two tables does not change which pairs match.'));
        $page->call('newQuestion')
            ->set('questionText', 'What does an inner join keep?')
            ->call('answerQuestionWithAi')
            ->assertSet('questionAnswer', 'An inner join keeps only rows that match on both sides, so the order of the two tables does not change which pairs match.')
            ->assertSee('Save question & answer')
            ->call('save')
            ->assertSet('mode', null)
            ->assertDispatched('questions-changed');
        $answered = collect(app(Questions::class)->list($this->by, $this->workspace))->firstWhere('text', 'What does an inner join keep?');
        $this->assertSame(['answered', 'An inner join keeps only rows that match on both sides, so the order of the two tables does not change which pairs match.'], [$answered->status, $answered->answer]);
    }

    public function test_student_can_quote_and_reply_to_tutors_message_with_targeted_context(): void
    {
        $session = $this->start($this->joins);
        $chat = $this->chat($session->id);

        // First, the tutor answers with a numbered explanation
        $this->engine->will(Fake::says("Here is how a page fault is handled:\n1. Trap to OS\n2. Save state\n3. Check validity\n4. Find free frame"));
        $chat->set('text', 'How is a page fault handled?')->call('send')
            ->assertSee('Here is how a page fault is handled')
            ->assertSee('4. Find free frame')
            ->assertSee('Reply');

        // Now the student replies quoting step 4
        $quotedText = "> 4. Find free frame\n\nWhat happens if there are no free frames available?";
        $this->engine->will(Fake::says('If no frames are free, the OS runs a page replacement algorithm to evict a page.'));

        $chat->set('text', $quotedText)->call('send')
            ->assertSee('Quoting tutor')
            ->assertSee('4. Find free frame')
            ->assertSee('What happens if there are no free frames available?')
            ->assertSee('If no frames are free, the OS runs a page replacement algorithm');
    }
}
