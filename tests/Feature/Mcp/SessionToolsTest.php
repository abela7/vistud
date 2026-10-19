<?php

namespace Tests\Feature\Mcp;

use App\Study\Modules;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A whole study session run from an AI app, by session_id (docs/specs/mcp-connections-plan.md §3.3, M6.3). */
class SessionToolsTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    private string $token;

    private string $course;

    private string $module;

    private string $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00:00', 'UTC'));
        $ada = $this->student();
        $by = $this->principal($ada);
        $this->course = app(Workspaces::class)->create($by, ['name' => 'Operating systems'])->id;
        $this->module = app(Modules::class)->create($by, $this->course, ['title' => 'Week 2: Scheduling'])->id;
        $this->topic = app(Topics::class)->create($by, $this->course, 'Round robin', $this->module)->id;
        $this->token = $this->connectApp($ada, 'write')['access_token'];
    }

    private function start(): string
    {
        $response = $this->callTool($this->token, 'start_session', ['course_id' => $this->course, 'module_id' => $this->module])->assertOk();
        $this->assertSame('recorded', $response->json('result.structuredContent.status'));

        return (string) $response->json('result.structuredContent.session_id');
    }

    public function test_start_gives_a_session_id_and_a_fenced_brief_and_a_second_start_names_the_open_one(): void
    {
        $response = $this->callTool($this->token, 'start_session', ['course_id' => $this->course, 'module_id' => $this->module])->assertOk();
        $id = (string) $response->json('result.structuredContent.session_id');
        $text = (string) $response->json('result.content.0.text');

        $this->assertNotSame('', $id);
        $this->assertStringContainsString("session_id: {$id}", $text);
        $this->assertStringContainsString('<material>', $text);
        $this->assertStringContainsString('Week 2: Scheduling', $text);
        $this->assertSame('running', DB::table('study_sessions')->where('id', $id)->value('state'));

        $again = $this->callTool($this->token, 'start_session', ['course_id' => $this->course])->assertOk();
        $this->assertTrue($again->json('result.isError'));
        $this->assertSame('rejected', $again->json('result.structuredContent.status'));
        $this->assertStringContainsString($id, (string) $again->json('result.content.0.text'));
    }

    public function test_the_topic_changes_by_id_and_is_logged_as_the_tutors(): void
    {
        $id = $this->start();

        $this->callTool($this->token, 'set_session_topic', ['session_id' => $id, 'topic_id' => $this->topic])
            ->assertOk()
            ->assertJsonPath('result.structuredContent.topic_id', $this->topic);

        $log = json_decode((string) DB::table('study_sessions')->where('id', $id)->value('topic_log'), true);
        $this->assertSame('tutor', end($log)['by']);
    }

    public function test_a_quiet_chat_pauses_the_session_and_a_save_resumes_it_but_a_read_does_not(): void
    {
        $id = $this->start();
        $this->travel(31)->minutes();

        // A read carrying the session id does not wake it.
        $this->callTool($this->token, 'study_brief', ['session_id' => $id])->assertOk();
        $this->assertSame(['paused', 'away'], array_values((array) DB::table('study_sessions')->where('id', $id)->first(['state', 'paused_by'])));

        $saved = $this->callTool($this->token, 'set_checkpoint', ['session_id' => $id, 'checkpoint' => 'Finished round robin.'])->assertOk();
        $this->assertTrue($saved->json('result.structuredContent.resumed'));
        $this->assertStringContainsString('running again', (string) $saved->json('result.content.0.text'));
        $this->assertSame('running', DB::table('study_sessions')->where('id', $id)->value('state'));
    }

    public function test_end_keeps_a_summary_written_before_and_says_the_minutes(): void
    {
        $id = $this->start();
        DB::table('study_sessions')->where('id', $id)->update(['summary' => 'From the app: covered FCFS.']);
        $this->travel(20)->minutes();
        $this->callTool($this->token, 'set_checkpoint', ['session_id' => $id, 'checkpoint' => 'Mid-way.'])->assertOk();
        $this->travel(5)->minutes();

        $ended = $this->callTool($this->token, 'end_session', ['session_id' => $id, 'summary' => 'Round robin and time slices.', 'checkpoint' => 'Next: priority scheduling.'])->assertOk();

        $this->assertStringContainsString('min studied', (string) $ended->json('result.content.0.text'));
        $row = DB::table('study_sessions')->where('id', $id)->first();
        $this->assertSame('ended', $row->state);
        $this->assertSame("From the app: covered FCFS.\n\nRound robin and time slices.", $row->summary);
        $this->assertSame('Next: priority scheduling.', $row->checkpoint);

        // An ended session takes no more saves, and says what to do.
        $after = $this->callTool($this->token, 'set_checkpoint', ['session_id' => $id, 'checkpoint' => 'Late.'])->assertOk();
        $this->assertTrue($after->json('result.isError'));
        $this->assertStringContainsString('start_session', (string) $after->json('result.content.0.text'));
    }

    public function test_a_session_taught_from_a_chat_app_counts_as_explanation_not_reading(): void
    {
        $id = $this->callTool($this->token, 'start_session', ['course_id' => $this->course, 'topic_id' => $this->topic])->json('result.structuredContent.session_id');
        $this->travel(10)->minutes();
        $this->callTool($this->token, 'set_checkpoint', ['session_id' => $id, 'checkpoint' => 'Taught round robin.'])->assertOk();
        $this->callTool($this->token, 'end_session', ['session_id' => $id])->assertOk();

        $exposure = DB::table('journal_entries')->where('kind', 'exposure')->orderByDesc('position')->first();
        $this->assertNotNull($exposure);
        $this->assertSame('explanation', json_decode((string) $exposure->body, true)['format']);
    }
}
