<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Activities;
use App\Study\Flashcards;
use App\Study\Instructions;
use App\Study\Modules;
use App\Study\NoteDoc;
use App\Study\Notes;
use App\Study\Questions;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\TutorMemories;
use App\Study\Workspaces;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * A chat app as the student's whole tutor (the owner's ask, 2026-10-24): it catches up in one call, builds the course,
 * keeps notes from anything it read, runs the assignment companion, and changes how it teaches when asked.
 */
class WholeTutorToolsTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $token;

    private string $course;

    private string $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ada = $this->student(['name' => 'Ada Lovelace']);
        $this->by = $this->principal($this->ada);
        $this->course = app(Workspaces::class)->create($this->by, ['name' => 'Database Systems'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->course, ['title' => 'Workshop A'])->id;
        $this->token = $this->connectApp($this->ada, 'full')['access_token'];
    }

    private function tool(string $tool, array $arguments): TestResponse
    {
        return $this->callTool($this->token, $tool, $arguments)->assertOk();
    }

    private function text(TestResponse $response): string
    {
        return (string) $response->json('result.content.0.text');
    }

    public function test_catch_up_gives_a_new_chat_everything_to_pick_up_from(): void
    {
        app(TutorMemories::class)->add($this->by, null, 'Learns best from worked examples.', 'tutor');
        app(Instructions::class)->set($this->by, 'me', 'Always relate concepts to my coursework.');
        $topic = app(Topics::class)->create($this->by, $this->course, '2NF', $this->module);
        app(Topics::class)->report($this->by, $topic->id, 'confused');
        app(Questions::class)->ask($this->by, $this->course, 'Why is a partial dependency a problem?', $topic->id);
        $cw = app(Activities::class)->create($this->by, $this->course, ['title' => 'CourseWork 01', 'kind' => 'assignment', 'due_on' => '2026-11-14']);
        $session = app(Sessions::class)->start($this->by, $this->course, $topic->id, $this->module);
        app(Sessions::class)->setSummary($this->by, $session->id, 'Covered 1NF; 2NF still unclear.');
        app(Sessions::class)->setCheckpoint($this->by, $session->id, 'Next: partial dependencies.');
        app(Sessions::class)->end($this->by, $session->id);

        $said = $this->text($this->tool('catch_up', []));
        foreach (['Ada', 'Learns best from worked examples.', 'Always relate concepts to my coursework.', 'None. Call start_session',
            "Database Systems (course_id {$this->course})", 'Covered 1NF; 2NF still unclear. Pick up: Next: partial dependencies.',
            'CourseWork 01 (assignment, stage understand, due 2026-11-14', "assignment_id {$cw->id}", 'Topics that confuse them: 2NF (Workshop A).',
            'Why is a partial dependency a problem?'] as $line) {
            $this->assertStringContainsString($line, $said);
        }
    }

    public function test_it_builds_the_course_keeps_a_note_from_a_video_and_changes_cards_and_topics(): void
    {
        $course = (string) $this->tool('create_course', ['name' => 'Software Engineering', 'code' => '5100COMP'])->json('result.structuredContent.course_id');
        $this->assertSame('Software Engineering', app(Workspaces::class)->find($this->by, $course)->name);

        $note = (string) $this->tool('create_note', [
            'course_id' => $this->course, 'module_id' => $this->module, 'title' => 'Normalisation explained',
            'text' => "## 2NF\n\n- No partial dependencies.", 'source' => 'https://youtube.com/watch?v=abc',
        ])->json('result.structuredContent.note_id');
        $made = app(Notes::class)->open($this->by, $note);
        $this->assertSame([$this->module, 'Normalisation explained'], [$made->moduleId, $made->title]);
        $this->assertStringContainsString('Source:', NoteDoc::markdown($made->doc));
        $this->assertStringContainsString('https://youtube.com/watch?v=abc', NoteDoc::markdown($made->doc));
        $this->assertStringContainsString('No partial dependencies.', NoteDoc::markdown($made->doc));

        $exam = $this->tool('create_assignment', ['course_id' => $this->course, 'module_id' => $this->module, 'title' => 'Final exam', 'kind' => 'exam', 'due_on' => '2027-01-20', 'due_time' => '09:30']);
        $activity = app(Activities::class)->find($this->by, (string) $exam->json('result.structuredContent.assignment_id'));
        $this->assertSame(['exam', '2027-01-20', '09:30', $this->module], [$activity->kind, $activity->dueOn, $activity->dueTime, $activity->moduleId]);

        $loose = app(Topics::class)->create($this->by, $this->course, 'BCNF');
        $this->tool('move_topic', ['course_id' => $this->course, 'module_id' => $this->module, 'topic_id' => $loose->id]);
        $this->assertSame($this->module, app(Topics::class)->find($this->by, $loose->id)->moduleId);

        $card = app(Flashcards::class)->add($this->by, $this->course, null, 'What is 2NF?', 'Wrong answer.');
        $this->tool('edit_flashcard', ['flashcard_id' => $card, 'back' => '1NF with no partial dependencies.']);
        $this->assertSame(['What is 2NF?', '1NF with no partial dependencies.'], [app(Flashcards::class)->find($this->by, $card)->front, app(Flashcards::class)->find($this->by, $card)->back]);
    }

    public function test_it_changes_how_it_teaches_and_the_students_standing_rules(): void
    {
        $session = app(Sessions::class)->start($this->by, $this->course, null, $this->module)->id;
        $said = $this->text($this->tool('set_tutoring', ['session_id' => $session, 'level' => 'master', 'method' => 'socratic', 'do' => 'Use exam questions.']));
        $this->assertStringContainsString('The tutor now teaches:', $said);
        $tutoring = app(Sessions::class)->find($this->by, $session)->tutoring;
        $this->assertSame(['exam', 'socratic', 'Use exam questions.'], [$tutoring['quiz'], $tutoring['method'], $tutoring['custom_do']]);

        $this->tool('set_instructions', ['for' => 'module', 'module_id' => $this->module, 'text' => 'Use the catering case study.']);
        $this->assertSame('Use the catering case study.', app(Instructions::class)->get($this->by, "module:{$this->module}"));
    }

    public function test_the_assignment_companion_works_over_the_connection(): void
    {
        $cw = app(Activities::class)->create($this->by, $this->course, ['title' => 'CourseWork 01', 'kind' => 'assignment'])->id;

        $added = $this->text($this->tool('add_assignment_source', ['course_id' => $this->course, 'assignment_id' => $cw, 'kind' => 'brief', 'title' => 'The brief', 'text' => 'Design a 3NF schema for the catering company.']));
        $this->assertStringNotContainsString('only be done', $added);
        $this->assertStringContainsString('3NF schema', $this->text($this->tool('assignment_source', ['course_id' => $this->course, 'assignment_id' => $cw, 'source' => 'S1'])));

        $this->tool('set_stage', ['course_id' => $this->course, 'assignment_id' => $cw, 'stage' => 'plan']);
        $this->assertSame('plan', app(Activities::class)->find($this->by, $cw)->stage);

        // Without its assignment_id the app is told what to pass.
        $this->assertStringContainsString('assignment_id', $this->text($this->callTool($this->token, 'set_stage', ['course_id' => $this->course, 'stage' => 'do'])));
    }

    public function test_every_tool_is_listed_on_one_page(): void
    {
        $listed = $this->mcp($this->token, 'tools/list')->assertOk();
        $this->assertNull($listed->json('result.nextCursor'));
        $this->assertCount(63, $listed->json('result.tools'));
    }
}
