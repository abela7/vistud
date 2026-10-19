<?php

namespace Tests\Feature\Mcp;

use App\Audit\AuditAction;
use App\Mcp\Tools\McpTool;
use App\Models\User;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Saving into ViStud from an AI app (docs/specs/mcp-connections-plan.md §3.1, §3.6, M6.3): by ids, inside a session,
 * with an acknowledgement, resent safely, never a secret, attributed to the chat app, and capped per day.
 */
class WriteToolsTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    private string $token;

    private string $course;

    private string $topic;

    private string $session;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00:00', 'UTC'));
        $ada = $this->student();
        $this->userId = $ada->id;
        $by = $this->principal($ada);
        $this->course = app(Workspaces::class)->create($by, ['name' => 'Operating systems'])->id;
        $module = app(Modules::class)->create($by, $this->course, ['title' => 'Week 2: Scheduling'])->id;
        $this->topic = app(Topics::class)->create($by, $this->course, 'Round robin', $module)->id;
        $this->token = $this->connectApp($ada, 'write')['access_token'];
        $this->session = (string) $this->callTool($this->token, 'start_session', ['course_id' => $this->course, 'topic_id' => $this->topic])->json('result.structuredContent.session_id');
    }

    private function cards(array $extra = []): array
    {
        return ['session_id' => $this->session, 'cards' => [['front' => 'What is a time slice?', 'back' => 'The CPU time each process gets before the next.', 'topic' => $this->topic]]] + $extra;
    }

    public function test_cards_save_inside_the_session_with_an_acknowledgement(): void
    {
        $response = $this->callTool($this->token, 'make_flashcards', $this->cards())->assertOk();

        $this->assertSame('recorded', $response->json('result.structuredContent.status'));
        $this->assertSame($this->session, $response->json('result.structuredContent.session_id'));
        $this->assertSame(1, DB::table('flashcards')->where('session_id', $this->session)->count());
    }

    public function test_a_save_without_a_session_is_refused_with_the_fix(): void
    {
        $response = $this->callTool($this->token, 'make_flashcards', ['course_id' => $this->course, 'cards' => [['front' => 'Q', 'back' => 'A']]])->assertOk();

        $this->assertTrue($response->json('result.isError'));
        $this->assertSame('rejected', $response->json('result.structuredContent.status'));
        $this->assertStringContainsString('missing: session_id', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, DB::table('flashcards')->count());
    }

    public function test_a_course_id_that_is_not_the_sessions_names_the_right_one_and_saves_nothing(): void
    {
        $other = app(Workspaces::class)->create($this->principal(User::query()->findOrFail($this->userId)), ['name' => 'Databases'])->id;

        $response = $this->callTool($this->token, 'make_flashcards', $this->cards(['course_id' => $other]))->assertOk();

        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString('Operating systems', (string) $response->json('result.content.0.text'));
        $this->assertSame(0, DB::table('flashcards')->count());
    }

    public function test_the_same_capture_key_saves_once_and_a_reused_key_with_other_content_is_refused(): void
    {
        $this->callTool($this->token, 'make_flashcards', $this->cards(['capture_key' => 'k-1']))->assertOk();
        $again = $this->callTool($this->token, 'make_flashcards', $this->cards(['capture_key' => 'k-1']))->assertOk();

        $this->assertSame('duplicate', $again->json('result.structuredContent.status'));
        $this->assertSame(1, DB::table('flashcards')->count());

        $other = $this->callTool($this->token, 'make_flashcards', ['session_id' => $this->session, 'capture_key' => 'k-1', 'cards' => [['front' => 'Other', 'back' => 'Card']]])->assertOk();
        $this->assertSame('rejected', $other->json('result.structuredContent.status'));
        $this->assertSame('capture_key_conflict', $other->json('result.structuredContent.reason'));
        $this->assertSame(1, DB::table('flashcards')->count());
    }

    public function test_a_password_or_key_is_never_saved(): void
    {
        $response = $this->callTool($this->token, 'make_flashcards', ['session_id' => $this->session, 'cards' => [['front' => 'My wifi', 'back' => 'password: hunter2!']]])->assertOk();

        $this->assertSame('rejected', $response->json('result.structuredContent.status'));
        $this->assertSame('sensitive', $response->json('result.structuredContent.reason'));
        $this->assertSame(0, DB::table('flashcards')->count());
        $this->assertStringNotContainsString('hunter2', (string) DB::table('mcp_calls')->get()->toJson());
    }

    public function test_a_quiz_is_recorded_as_judged_by_the_chat_apps_company_through_mcp(): void
    {
        $this->callTool($this->token, 'record_quiz', [
            'session_id' => $this->session,
            'kind' => 'quiz',
            'topic_id' => $this->topic,
            'questions' => [['asked' => 'What happens when a time slice ends?', 'answer' => 'The next process runs.', 'result' => 'correct']],
        ])->assertOk()->assertJsonPath('result.structuredContent.status', 'recorded');

        $attempt = DB::table('journal_entries')->where('kind', 'attempt')->orderByDesc('position')->first();
        $this->assertNotNull($attempt);
        $body = json_decode((string) $attempt->body, true);
        $this->assertSame('mcp', $attempt->actor_channel);
        $this->assertSame('mcp', $body['judge']['via']);
        $this->assertSame('anthropic-connector', $body['judge']['model']);
        $this->assertStringStartsWith('mcp-rules@', $body['judge']['prompt']);
    }

    public function test_a_topic_mark_from_a_chat_app_is_the_chat_apps(): void
    {
        $this->callTool($this->token, 'set_topic_status', ['session_id' => $this->session, 'topic' => $this->topic, 'status' => 'understood', 'reason' => 'Explained it back correctly.'])
            ->assertOk()
            ->assertJsonPath('result.structuredContent.status', 'recorded');

        $this->assertSame(['understood', 'chat'], array_values((array) DB::table('topics')->where('id', $this->topic)->first(['status', 'status_by'])));
    }

    public function test_a_flashcard_answer_says_who_graded_it(): void
    {
        $by = $this->principal(User::query()->findOrFail($this->userId));
        $card = app(Flashcards::class)->add($by, $this->course, $this->topic, 'Q?', 'A.');

        $this->callTool($this->token, 'answer_flashcard', ['course_id' => $this->course, 'card_id' => $card, 'result' => 'correct'])
            ->assertOk()->assertJsonPath('result.structuredContent.graded_by', 'ai');
        $this->callTool($this->token, 'answer_flashcard', ['course_id' => $this->course, 'card_id' => $card, 'result' => 'partial', 'graded_by' => 'student'])
            ->assertOk()->assertJsonPath('result.structuredContent.graded_by', 'student');

        $judged = DB::table('journal_entries')->where('kind', 'attempt')->orderBy('position')->get()->map(fn ($e) => json_decode((string) $e->body, true)['judged_by'])->all();
        $this->assertSame(['ai', 'self'], array_slice($judged, -2));
    }

    public function test_a_read_only_app_neither_sees_nor_runs_a_write(): void
    {
        $reader = $this->connectApp($this->student(), 'read')['access_token'];

        $names = collect($this->mcp($reader, 'tools/list')->json('result.tools'))->pluck('name');
        $this->assertNotContains('make_flashcards', $names);
        // Not listed means not there: the call is a JSON-RPC "not found", and nothing is saved.
        $this->assertStringContainsString('not found', (string) $this->callTool($reader, 'make_flashcards', $this->cards())->json('error.message'));
        $this->assertSame(0, DB::table('flashcards')->count());
    }

    public function test_the_daily_cap_stops_saves_and_is_audited(): void
    {
        $connection = DB::table('mcp_connections')->first();
        DB::table('mcp_calls')->insert(['id' => (string) Str::uuid(), 'learner_id' => $connection->learner_id, 'connection_id' => $connection->id, 'tool' => 'make_flashcards', 'outcome' => 'ok', 'chars' => 0, 'effects' => json_encode(['rows' => McpTool::DAILY_ROWS]), 'created_at' => now()]);

        $response = $this->callTool($this->token, 'make_flashcards', $this->cards())->assertOk();

        $this->assertSame('rate_limited', $response->json('result.structuredContent.reason'));
        $this->assertSame(0, DB::table('flashcards')->count());
        $this->assertCount(1, $this->auditRows(AuditAction::MCP_RATE_LIMITED));
    }
}
