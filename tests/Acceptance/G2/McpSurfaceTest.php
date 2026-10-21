<?php

namespace Tests\Acceptance\G2;

use App\Mcp\ToolInfo;
use Illuminate\Support\Str;
use Tests\Concerns\ConnectsAiApps;
use Tests\TestCase;

/**
 * G2 over MCP: every tool, resource and prompt, called as A with B's ids, with A's own ids and searches written near
 * B's content, and interleaved with B's own calls on the same worker.
 */
class McpSurfaceTest extends TestCase
{
    use ConnectsAiApps, World;

    private const PROMPTS = ['start_study_session', 'quiz_me', 'review_topic', 'weekly_brief'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    /** Every argument a tool can take, aimed at $who's records; searches are written near B's concept. */
    private function arguments(string $who): array
    {
        $ids = $this->ids($who);
        $near = $this->learners['B']['concept'];

        return [
            'course_id' => $ids['course'], 'module_id' => $ids['module'], 'folder_id' => $ids['folder'], 'topic_id' => $ids['topic'],
            'note_id' => $ids['note'], 'question_id' => $ids['question'], 'card_id' => $ids['card'], 'memory_id' => $ids['memory'],
            'file_id' => $ids['file'], 'session_id' => $ids['session'], 'assignment_id' => $ids['assignment'], 'quiz_id' => $ids['session'],
            'query' => $near, 'search' => $near, 'q' => $near, 'text' => $near.' notes', 'topic' => $near, 'name' => $near,
            'page' => 1, 'pages' => 'all', 'part' => 'all', 'from' => '2026-01-01', 'to' => '2027-01-01',
        ];
    }

    public function test_every_tool_called_as_a_with_bs_and_cs_ids_leaks_nothing_and_changes_nothing(): void
    {
        $token = $this->connectApp($this->learners['A']['user'], 'full')['access_token'];
        $before = [$this->footprint('B'), $this->footprint('C')];

        foreach (['B', 'C'] as $other) {
            foreach (array_keys(ToolInfo::TOOLS) as $tool) {
                $body = (string) $this->callTool($token, $tool, $this->arguments($other))->getContent();
                $this->assertNothingOfOthers($body, "tool {$tool} with {$other}'s ids");
            }
        }

        $this->assertSame($before, [$this->footprint('B'), $this->footprint('C')], 'A changed another learner\'s records through MCP.');
    }

    public function test_every_tool_called_as_a_on_a_s_own_records_with_searches_near_b_leaks_nothing(): void
    {
        $token = $this->connectApp($this->learners['A']['user'], 'full')['access_token'];

        foreach (array_keys(ToolInfo::TOOLS) as $tool) {
            $body = (string) $this->callTool($token, $tool, $this->arguments('A'))->getContent();
            $this->assertNothingOfOthers($body, "tool {$tool} on A's own records");
        }
        // The search really runs: A's own concept, spelt one letter from B's, is found.
        $found = (string) $this->callTool($token, 'search_everything', ['query' => $this->learners['A']['concept']])->getContent();
        $this->assertStringContainsString($this->marker('A'), $found);
    }

    public function test_resources_and_prompts_leak_nothing_for_any_learners_ids(): void
    {
        $token = $this->connectApp($this->learners['A']['user'], 'full')['access_token'];

        foreach (['A', 'B', 'C'] as $who) {
            $ids = $this->ids($who);
            foreach (["vistud://courses/{$ids['course']}/brief", "vistud://notes/{$ids['note']}", "vistud://files/{$ids['file']}/pages/1", 'vistud://files/'.Str::uuid().'/pages/1'] as $uri) {
                $this->assertNothingOfOthers((string) $this->mcp($token, 'resources/read', ['uri' => $uri])->getContent(), "resource {$uri} ({$who})");
            }
        }
        foreach (['resources/list', 'resources/templates/list', 'prompts/list', 'tools/list'] as $method) {
            $this->assertNothingOfOthers((string) $this->mcp($token, $method)->getContent(), $method);
        }
        foreach (self::PROMPTS as $prompt) {
            foreach (['A', 'B', 'C'] as $who) {
                $arguments = ['topic' => $this->learners['B']['concept'], 'course' => "Course {$this->learners[$who]['concept']}", 'course_id' => $this->ids($who)['course']];
                $this->assertNothingOfOthers((string) $this->mcp($token, 'prompts/get', ['name' => $prompt, 'arguments' => $arguments])->getContent(), "prompt {$prompt} ({$who})");
            }
        }
    }

    public function test_interleaved_calls_on_one_worker_keep_each_learner_to_their_own(): void
    {
        $tokens = [];
        foreach (['A', 'B', 'C'] as $who) {
            $tokens[$who] = $this->connectApp($this->learners[$who]['user'], 'read')['access_token'];
        }
        $reads = ['courses', 'search_everything', 'memories', 'study_brief', 'notes', 'questions', 'findings', 'assignments'];
        for ($round = 0; $round < 3; $round++) {
            foreach ($reads as $tool) {
                foreach (['B', 'A', 'C', 'A'] as $who) {
                    $body = (string) $this->callTool($tokens[$who], $tool, $this->arguments($who))->getContent();
                    $this->assertNothingOfOthers($body, "interleaved {$tool} as {$who}", $who);
                }
            }
        }
        // Each still sees their own course.
        foreach (['A', 'B', 'C'] as $who) {
            $this->assertStringContainsString($this->marker($who), (string) $this->callTool($tokens[$who], 'courses')->getContent(), "{$who} lost their own course");
        }
    }
}
