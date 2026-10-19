<?php

namespace Tests\Feature\Web;

use App\Livewire\Workspaces\StudySession;
use App\Mcp\SessionWork;
use App\Models\User;
use App\Study\Modules;
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
 * A study session worked on from an AI app, as its page shows it (docs/specs/mcp-connections-plan.md §3.7, M6.3): the
 * "connected" chip, what the app saved in the rail and on the end screen, marks said to be the app's with Undo, and the
 * app's quiz with Undo.
 */
class SessionAppWorkTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private string $token;

    private string $course;

    private string $topic;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $by = $this->principal($this->ada);
        $this->course = app(Workspaces::class)->create($by, ['name' => 'Operating systems'])->id;
        $module = app(Modules::class)->create($by, $this->course, ['title' => 'Week 2: Scheduling'])->id;
        $this->topic = app(Topics::class)->create($by, $this->course, 'Round robin', $module)->id;
        $this->token = $this->connectApp($this->ada, 'write')['access_token'];
        $this->session = (string) $this->callTool($this->token, 'start_session', ['course_id' => $this->course, 'topic_id' => $this->topic])->json('result.structuredContent.session_id');
    }

    private function page()
    {
        return Livewire::actingAs($this->ada)->test(StudySession::class, ['workspaceId' => $this->course, 'sessionId' => $this->session]);
    }

    private function teach(): void
    {
        $this->callTool($this->token, 'save_key_points', ['session_id' => $this->session, 'points' => [['text' => 'A time slice is the CPU time each process gets.']]])->assertJsonPath('result.structuredContent.status', 'recorded');
        $this->callTool($this->token, 'record_quiz', ['session_id' => $this->session, 'kind' => 'quiz', 'topic_id' => $this->topic, 'questions' => [['asked' => 'What ends a time slice?', 'answer' => 'The timer.', 'result' => 'correct']]])->assertJsonPath('result.structuredContent.status', 'recorded');
        $this->callTool($this->token, 'set_topic_status', ['session_id' => $this->session, 'topic' => $this->topic, 'status' => 'understood', 'reason' => 'Explained it back.'])->assertJsonPath('result.structuredContent.status', 'recorded');
    }

    public function test_the_page_says_a_chat_app_is_connected_while_it_is_working_and_stops_after_half_an_hour(): void
    {
        // The app started the session, so it is connected to it.
        $this->page()->assertSee('Anthropic is connected to this session');

        $this->travel(31)->minutes();
        $this->page()->assertDontSee('is connected to this session');

        $this->callTool($this->token, 'set_checkpoint', ['session_id' => $this->session, 'checkpoint' => 'Time slices done.'])->assertOk();
        $this->page()->assertSee('Anthropic is connected to this session');
    }

    public function test_what_the_app_saved_is_in_the_work_and_on_the_end_screen_with_its_marks(): void
    {
        $this->teach();

        $work = app(SessionWork::class)->for($this->principal($this->ada), $this->session);
        $this->assertSame(['finding' => 1], $work['saved']);
        $this->assertSame(1, $work['quizzes']);
        $this->assertSame(['Anthropic'], $work['apps']);
        $this->assertSame('understood', $work['statuses'][$this->topic]['to']);

        $this->page()->call('confirmEnd')
            ->assertSee('Saved in this session')
            ->assertSee('1 quiz or test')
            ->assertSee('Set by a chat app (Anthropic): ')
            ->assertSee('Undo');
    }

    public function test_something_deleted_since_no_longer_counts(): void
    {
        $this->teach();
        DB::table('findings')->delete();
        DB::table('quizzes')->delete();

        $work = app(SessionWork::class)->for($this->principal($this->ada), $this->session);
        $this->assertSame([], $work['saved']);
        $this->assertSame(0, $work['quizzes']);
    }

    public function test_undo_puts_the_topic_back_as_it_was(): void
    {
        $this->teach();
        $this->assertSame(['understood', 'chat'], array_values((array) DB::table('topics')->where('id', $this->topic)->first(['status', 'status_by'])));

        $this->page()->call('undoAppMark', $this->topic);

        $this->assertNull(DB::table('topics')->where('id', $this->topic)->value('status'));
    }

    public function test_undo_leaves_a_status_the_student_has_set_since(): void
    {
        $this->teach();
        app(Topics::class)->report($this->principal($this->ada), $this->topic, 'confused');

        $this->page()->call('undoAppMark', $this->topic);

        $this->assertSame('confused', DB::table('topics')->where('id', $this->topic)->value('status'));
    }

    public function test_the_apps_quiz_is_tagged_and_offers_undo_in_place_of_delete(): void
    {
        $this->teach();

        $this->page()->assertSee('Set by a chat app (Anthropic)')->assertSeeHtml('aria-label="Undo this quiz"');
    }

    public function test_another_students_session_shows_nothing_of_it(): void
    {
        $this->teach();
        $other = $this->principal($this->student());

        $this->assertSame(0, app(SessionWork::class)->for($other, $this->session)['calls']);
    }
}
