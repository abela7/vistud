<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Apps\Panels;
use App\Mcp\Provider;
use App\Mcp\ToolInfo;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Modules;
use App\Study\Sessions;
use App\Study\Topics;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Quiz, status, session, flashcard and confirmation panels (docs/specs/mcp-connections-plan.md M6.6).
 * A linked tool still returns its whole text. A confirmation card only opens the ViStud page.
 */
class AppsTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $token;

    private string $course;

    private string $module;

    private string $topic;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->course = app(Workspaces::class)->create($this->by, ['name' => 'Databases'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->course, ['title' => 'Week 2'])->id;
        $this->topic = app(Topics::class)->create($this->by, $this->course, 'Joins', $this->module)->id;
        $this->session = app(Sessions::class)->start($this->by, $this->course, $this->topic, $this->module)->id;
        $this->token = $this->connectApp($this->ada, 'write')['access_token'];
    }

    public function test_a_panel_is_html_with_a_connect_rule_and_a_domain_per_company(): void
    {
        $claude = $this->mcp($this->token, 'resources/read', ['uri' => 'ui://resources/quiz-round'])->assertOk();
        $html = (string) $claude->json('result.contents.0.text');
        $this->assertSame('text/html;profile=mcp-app', $claude->json('result.contents.0.mimeType'));
        $this->assertStringContainsString('callServerTool', $html);
        $this->assertStringContainsString('record_quiz', $html);
        $this->assertStringNotContainsString('localStorage', $html);
        $this->assertStringContainsString('min-height: 44px', $html);
        $this->assertStringContainsString('system-ui', $html);

        $meta = $claude->json('result.contents.0._meta.ui');
        $this->assertContains(rtrim((string) config('app.url'), '/'), $meta['csp']['connectDomains']);
        $this->assertSame(substr(hash('sha256', rtrim((string) url('/mcp'), '/')), 0, 32).'.claudemcpcontent.com', $meta['domain']);

        $openai = $this->connectApp($this->student(), 'read', 'https://chatgpt.com/connector/oauth')['access_token'];
        $chat = $this->mcp($openai, 'resources/read', ['uri' => 'ui://resources/quiz-round'])->assertOk();
        $this->assertArrayNotHasKey('domain', $chat->json('result.contents.0._meta.ui') ?? []);
        $this->assertSame(Provider::OPENAI, DB::table('mcp_connections')->where('provider', Provider::OPENAI)->value('provider'));
    }

    public function test_every_linked_tool_names_its_panel_and_still_returns_the_whole_text(): void
    {
        $tools = collect($this->mcp($this->token, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');
        foreach (array_keys(Panels::LINKED) as $name) {
            if (! ToolInfo::allows('write', ToolInfo::of($name)['level'])) {
                continue;
            }
            $this->assertSame(app(Panels::LINKED[$name])->uri(), $tools[$name]['_meta']['ui']['resourceUri'], $name);
        }

        $asked = 'What does a left join keep?';
        $shown = $this->callTool($this->token, 'show_quiz', [
            'course_id' => $this->course,
            'session_id' => $this->session,
            'topic_id' => $this->topic,
            'questions' => [['asked' => $asked, 'choices' => ['Every left row', 'Only matches'], 'right' => 'Every left row']],
        ])->assertOk();
        $this->assertFalse((bool) $shown->json('result.isError'));
        $this->assertStringContainsString($asked, (string) $shown->json('result.content.0.text'));
        $this->assertStringContainsString('not saved yet', (string) $shown->json('result.content.0.text'));
        $this->assertSame($asked, $shown->json('result.structuredContent.questions.0.asked'));
        $this->assertNotSame('', (string) $shown->json('result.structuredContent.ui_version'));
        $this->assertSame(0, DB::table('quizzes')->count());

        $marked = $this->callTool($this->token, 'set_topic_status', [
            'course_id' => $this->course,
            'session_id' => $this->session,
            'topic' => 'Joins',
            'status' => 'understood',
            'reason' => 'They explained a left join.',
        ])->assertOk();
        $this->assertStringContainsString('Joins', (string) $marked->json('result.content.0.text'));
        $this->assertNotSame('', (string) $marked->json('result.structuredContent.ui_version'));

        $ended = $this->callTool($this->token, 'end_session', [
            'session_id' => $this->session,
            'summary' => 'Left joins.',
            'checkpoint' => 'Right joins next.',
        ])->assertOk();
        $this->assertStringContainsString('Session ended', (string) $ended->json('result.content.0.text'));
    }

    public function test_another_students_topic_looks_like_one_that_never_existed(): void
    {
        $grace = $this->principal($this->student());
        $theirs = app(Topics::class)->create($grace, app(Workspaces::class)->create($grace, ['name' => 'Chemistry'])->id, 'Bonds')->id;
        $never = (string) Str::uuid();
        $question = [['asked' => 'Why?']];

        $missing = $this->callTool($this->token, 'show_quiz', [
            'course_id' => $this->course, 'session_id' => $this->session, 'topic_id' => $never, 'questions' => $question,
        ])->json('result');
        $foreign = $this->callTool($this->token, 'show_quiz', [
            'course_id' => $this->course, 'session_id' => $this->session, 'topic_id' => $theirs, 'questions' => $question,
        ])->json('result');

        $this->assertSame($missing, $foreign);
        $this->assertTrue($foreign['isError']);
        $this->assertStringNotContainsString('Bonds', json_encode($foreign));
    }

    public function test_the_confirmation_card_only_opens_the_page(): void
    {
        $page = $this->get('/mcp/panels/confirm')->assertOk();
        $page->assertSee('openLink', false);
        $page->assertSee('Nothing changes until you press Yes', false);
        $page->assertDontSee('localStorage', false);
        $page->assertDontSee('/connections/confirm/', false);

        $full = $this->connectApp($this->ada, 'full')['access_token'];
        $asked = $this->callTool($full, 'rename_module', ['module_id' => $this->module, 'new_title' => 'Scheduling'])->assertOk();
        $text = (string) $asked->json('result.content.0.text');
        $this->assertStringContainsString('/connections/confirm/', $text);
        $this->assertStringContainsString('only the student can', $text);
        $this->assertSame('Week 2', DB::table('modules')->where('title', 'Week 2')->value('title'));
        $this->assertSame(Panels::meta('rename_module')['resourceUri'], collect($this->mcp($full, 'tools/list')->json('result.tools'))->keyBy('name')['rename_module']['_meta']['ui']['resourceUri']);
    }
}
