<?php

namespace Tests\Feature\Mcp;

use App\Livewire\Study\Connections;
use App\Mcp\Undo;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\Questions;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * "Undo what this app did" (docs/specs/mcp-connections-plan.md §3.8, M6.3): what a connection saved in a window is
 * taken back by the ids in its call record, and nothing else is touched.
 */
class UndoConnectionTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $token;

    private string $course;

    private string $topic;

    private string $session;

    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->course = app(Workspaces::class)->create($this->by, ['name' => 'Operating systems'])->id;
        $module = app(Modules::class)->create($this->by, $this->course, ['title' => 'Week 2: Scheduling'])->id;
        $this->topic = app(Topics::class)->create($this->by, $this->course, 'Round robin', $module)->id;
        $this->token = $this->connectApp($this->ada, 'write')['access_token'];
        $this->connection = (string) DB::table('mcp_connections')->value('id');
        $this->session = (string) $this->callTool($this->token, 'start_session', ['course_id' => $this->course, 'topic_id' => $this->topic])->json('result.structuredContent.session_id');
    }

    private function save(): void
    {
        $this->callTool($this->token, 'make_flashcards', ['session_id' => $this->session, 'cards' => [['front' => 'What is a time slice?', 'back' => 'CPU time per turn.', 'topic' => $this->topic]]])->assertJsonPath('result.structuredContent.status', 'recorded');
        $this->callTool($this->token, 'save_key_points', ['session_id' => $this->session, 'points' => [['text' => 'A time slice is fixed.']]])->assertJsonPath('result.structuredContent.status', 'recorded');
        $this->callTool($this->token, 'add_questions', ['session_id' => $this->session, 'questions' => [['text' => 'Why not a longer slice?']]])->assertJsonPath('result.structuredContent.status', 'recorded');
        $this->callTool($this->token, 'record_quiz', ['session_id' => $this->session, 'kind' => 'quiz', 'topic_id' => $this->topic, 'questions' => [['asked' => 'What ends a slice?', 'answer' => 'The timer.', 'result' => 'correct']]])->assertJsonPath('result.structuredContent.status', 'recorded');
        $this->callTool($this->token, 'set_topic_status', ['session_id' => $this->session, 'topic' => $this->topic, 'status' => 'understood', 'reason' => 'Explained it back.'])->assertJsonPath('result.structuredContent.status', 'recorded');
    }

    private function undo(string $window = 'all'): array
    {
        return app(Undo::class)->run($this->by, $this->connection, $window);
    }

    public function test_it_takes_back_what_the_app_saved_and_nothing_else(): void
    {
        $own = app(Flashcards::class)->add($this->by, $this->course, $this->topic, 'My card?', 'Mine.');
        $this->save();
        $this->assertSame(1, DB::table('findings')->count());

        $done = $this->undo();

        $this->assertSame(['flashcards' => 1, 'key_points' => 1, 'questions' => 1, 'answers' => 0, 'quizzes' => 1, 'marks' => 1], $done);
        $this->assertSame([$own], DB::table('flashcards')->pluck('id')->all());
        $this->assertSame(0, DB::table('findings')->count());
        $this->assertSame(0, DB::table('questions')->count());
        $this->assertSame(0, DB::table('quizzes')->count());
        $this->assertNull(DB::table('topics')->where('id', $this->topic)->value('status'));
    }

    public function test_a_quiz_is_retracted_in_the_journal_not_erased(): void
    {
        $this->save();
        $before = DB::table('journal_entries')->count();

        $this->undo();

        $this->assertSame(0, DB::table('quizzes')->count());
        $this->assertGreaterThan($before, DB::table('journal_entries')->count());
        $this->assertSame(1, DB::table('journal_entries')->where('kind', 'amendment')->count());
        $this->assertSame(1, DB::table('journal_entries')->where('kind', 'attempt')->count());
    }

    public function test_a_second_undo_takes_back_nothing_more(): void
    {
        $this->save();
        $this->undo();

        $this->assertSame(['flashcards' => 0, 'key_points' => 0, 'questions' => 0, 'answers' => 0, 'quizzes' => 0, 'marks' => 0], $this->undo());
        $this->assertNull(Undo::words($this->undo()));
    }

    public function test_a_status_the_student_set_since_stays(): void
    {
        $this->save();
        app(Topics::class)->report($this->by, $this->topic, 'confused');

        $this->assertSame(0, $this->undo()['marks']);
        $this->assertSame('confused', DB::table('topics')->where('id', $this->topic)->value('status'));
    }

    public function test_an_answer_the_app_wrote_is_taken_back_and_one_the_student_gave_stays(): void
    {
        $theirs = app(Questions::class)->ask($this->by, $this->course, 'What is a quantum?')->id;
        $mine = app(Questions::class)->ask($this->by, $this->course, 'What is a context switch?')->id;
        app(Questions::class)->setStatus($this->by, $theirs, 'answered', 'Their own answer.');
        $this->callTool($this->token, 'answer_question', ['session_id' => $this->session, 'question_id' => $mine, 'answer' => 'Saving one process and loading another.'])->assertJsonPath('result.structuredContent.status', 'recorded');
        $this->assertSame('ai', DB::table('questions')->where('id', $mine)->value('answered_by'));

        $this->assertSame(1, $this->undo()['answers']);

        $this->assertSame(['pending', null], array_values((array) DB::table('questions')->where('id', $mine)->first(['status', 'answer'])));
        $this->assertSame('answered', DB::table('questions')->where('id', $theirs)->value('status'));
    }

    public function test_the_window_leaves_older_work_alone(): void
    {
        $this->callTool($this->token, 'make_flashcards', ['session_id' => $this->session, 'cards' => [['front' => 'Old?', 'back' => 'Old.']]])->assertOk();
        $this->travel(3)->hours();
        $this->callTool($this->token, 'make_flashcards', ['session_id' => $this->session, 'cards' => [['front' => 'New?', 'back' => 'New.']]])->assertOk();

        $this->assertSame(1, $this->undo('hour')['flashcards']);
        $this->assertSame(['Old?'], DB::table('flashcards')->pluck('front')->all());

        $this->assertSame(1, $this->undo('day')['flashcards']);
        $this->assertSame(0, DB::table('flashcards')->count());
    }

    public function test_the_screen_shows_what_it_will_take_back_and_does_it(): void
    {
        $this->save();

        Livewire::actingAs($this->ada)->test(Connections::class)
            ->assertSee('Undo what it did')
            ->call('chooseUndo', $this->connection)
            ->assertSee('1 flashcard, 1 key point, 1 question, 1 quiz or test, 1 topic mark')
            ->assertSee('Not taken back: topics it added')
            ->call('undo')
            ->assertSee('Taken back: 1 flashcard')
            ->assertDontSeeHtml('data-test="undo-panel"');

        $this->assertSame(0, DB::table('flashcards')->count());
    }

    public function test_the_screen_says_when_there_is_nothing_and_a_read_only_app_has_no_undo(): void
    {
        Livewire::actingAs($this->ada)->test(Connections::class)
            ->call('chooseUndo', $this->connection)
            ->assertSee('Nothing to take back in that time.');

        $reader = $this->student();
        $this->connectApp($reader, 'read');
        Livewire::actingAs($reader)->test(Connections::class)->assertDontSee('Undo what it did');
    }

    public function test_another_students_connection_cannot_be_chosen(): void
    {
        $this->save();
        $mallory = $this->student();

        Livewire::actingAs($mallory)->test(Connections::class)
            ->call('chooseUndo', $this->connection)
            ->assertSet('undoFor', null)
            ->assertSee('already gone');
        $this->assertSame(1, DB::table('flashcards')->count());
    }
}
