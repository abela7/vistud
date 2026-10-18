<?php

namespace Tests\Feature\Mcp;

use App\Mcp\ToolInfo;
use App\Platform\Access\Principal;
use App\Study\Flashcards;
use App\Study\Modules;
use App\Study\Questions;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The read tools an AI app gets over MCP (docs/specs/mcp-connections-plan.md M6.2). */
class ReadToolsTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    private string $token;

    private string $course;

    private string $week2;

    private string $joins;

    private Principal $by;

    protected function setUp(): void
    {
        parent::setUp();
        $ada = $this->student();
        $by = $this->principal($ada);
        $this->course = app(Workspaces::class)->create($by, ['name' => 'Databases'])->id;
        $this->week2 = app(Modules::class)->create($by, $this->course, ['title' => 'Week 2: SQL joins'])->id;
        $topics = app(Topics::class);
        $this->joins = $topics->create($by, $this->course, 'Joins', $this->week2)->id;
        $topics->report($by, $this->joins, 'confused');
        app(Questions::class)->ask($by, $this->course, 'Why does a left join keep unmatched rows?', $this->joins, $this->week2);
        app(Flashcards::class)->add($by, $this->course, $this->joins, 'What does a left join keep?', 'Every row of the left table.');
        $this->by = $by;
        $this->token = $this->connectApp($ada, 'read')['access_token'];
    }

    public function test_every_tool_in_the_table_is_listed_with_its_four_hints(): void
    {
        $tools = collect($this->mcp($this->token, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');

        $this->assertSame(array_keys(ToolInfo::TOOLS), $tools->keys()->all());
        foreach (ToolInfo::TOOLS as $name => $info) {
            $hints = $tools[$name]['annotations'];
            $this->assertSame($info['read_only'], $hints['readOnlyHint'], $name);
            $this->assertSame($info['destructive'], $hints['destructiveHint'], $name);
            $this->assertSame($info['idempotent'], $hints['idempotentHint'], $name);
            $this->assertFalse($hints['openWorldHint'], $name);
            if ($info['course']) {
                $this->assertContains('course_id', $tools[$name]['inputSchema']['required'], $name);
            }
        }
    }

    public function test_courses_gives_the_ids_the_other_tools_take(): void
    {
        $text = (string) $this->callTool($this->token, 'courses')->assertOk()->json('result.content.0.text');

        $this->assertStringContainsString($this->course, $text);
        $this->assertStringContainsString('Databases', $text);
    }

    public function test_topics_says_where_the_student_stands_and_who_said_so(): void
    {
        $response = $this->callTool($this->token, 'topics', ['course_id' => $this->course])->assertOk();

        $this->assertFalse((bool) $response->json('result.isError'));
        $text = (string) $response->json('result.content.0.text');
        $this->assertStringContainsString($this->joins, $text);
        $this->assertStringContainsString('confusing', $text);
        $this->assertStringContainsString('the student', $text);
    }

    public function test_a_module_can_narrow_a_course_tool_but_only_one_of_that_course(): void
    {
        $this->callTool($this->token, 'questions', ['course_id' => $this->course, 'module_id' => $this->week2])
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        // A module of the same student's other course is not "in" this one.
        $other = app(Workspaces::class)->create($this->by, ['name' => 'Other']);
        $elsewhere = app(Modules::class)->create($this->by, $other->id, ['title' => 'Elsewhere'])->id;
        $this->callTool($this->token, 'questions', ['course_id' => $this->course, 'module_id' => $elsewhere])
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'Not found: there is no such thing in your ViStud.');
    }

    public function test_a_course_tool_without_a_course_id_is_an_error_the_app_sees(): void
    {
        $this->callTool($this->token, 'topics')->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_flashcards_due_returns_the_cards_with_ids_and_fences_their_words(): void
    {
        $response = $this->callTool($this->token, 'flashcards_due', ['course_id' => $this->course])->assertOk();

        $this->assertSame('What does a left join keep?', $response->json('result.structuredContent.cards.0.front'));
        $this->assertStringContainsString('<material>', (string) $response->json('result.content.0.text'));
    }

    public function test_current_session_says_when_none_is_open(): void
    {
        $this->callTool($this->token, 'current_session')
            ->assertOk()
            ->assertJsonPath('result.structuredContent.open', false);
    }

    public function test_every_call_writes_one_row_without_text(): void
    {
        $this->callTool($this->token, 'topics', ['course_id' => $this->course]);
        $this->callTool($this->token, 'topics');

        $rows = DB::table('mcp_calls')->orderBy('created_at')->get();
        $this->assertSame(['ok', 'not_found'], $rows->pluck('outcome')->all());
        $this->assertSame($this->course, $rows[0]->target_id);
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('Joins', json_encode($row));
        }
    }
}
