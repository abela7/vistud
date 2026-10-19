<?php

namespace Tests\Feature\Mcp;

use App\Audit\AuditAction;
use App\Mcp\Confirmations;
use App\Mcp\Connections;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Topics;
use App\Study\TutorMemories;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Changes a chat app asks for and only the student makes (docs/specs/mcp-connections-plan.md §3.4, M6.5): the tool
 * changes nothing, the ViStud page shows the change read-only, and the POST runs it once.
 */
class ConfirmationTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $token;

    private string $course;

    private string $module;

    private string $topic;

    private string $note;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->course = app(Workspaces::class)->create($this->by, ['name' => 'Operating systems'])->id;
        $this->module = app(Modules::class)->create($this->by, $this->course, ['title' => 'Week 2'])->id;
        $this->topic = app(Topics::class)->create($this->by, $this->course, 'Paging', $this->module)->id;
        $this->note = app(Notes::class)->create($this->by, 'module', $this->module, 'Scheduling')->id;
        $this->token = $this->connectApp($this->ada, 'full')['access_token'];
    }

    private function ask(string $tool, array $arguments): string
    {
        $response = $this->callTool($this->token, $tool, $arguments)->assertOk();
        $response->assertJsonPath('result.structuredContent.status', 'pending_confirmation');

        return (string) $response->json('result.structuredContent.confirm_url');
    }

    public function test_the_tool_changes_nothing_and_answers_with_a_link(): void
    {
        $url = $this->ask('rename_module', ['module_id' => $this->module, 'new_title' => 'Scheduling']);

        $this->assertStringContainsString('/connections/confirm/', $url);
        $this->assertSame('Week 2', DB::table('modules')->where('id', $this->module)->value('title'));
        $this->assertSame(1, DB::table('mcp_confirmations')->count());
    }

    public function test_opening_the_page_shows_the_change_and_changes_nothing(): void
    {
        $url = $this->ask('rename_module', ['module_id' => $this->module, 'new_title' => 'Scheduling']);

        $this->actingAs($this->ada)->get($url)->assertOk()->assertSee('Rename the module')->assertSee('Week 2')->assertSee('Scheduling')->assertSee('Anthropic');
        $this->get($url)->assertOk();
        $this->assertSame('Week 2', DB::table('modules')->where('id', $this->module)->value('title'));
        $this->assertNull(DB::table('mcp_confirmations')->value('used_at'));
    }

    public function test_yes_makes_the_change_once_and_the_link_then_answers_not_found(): void
    {
        $url = $this->ask('rename_module', ['module_id' => $this->module, 'new_title' => 'Scheduling']);

        $this->actingAs($this->ada)->post($url)->assertOk()->assertSee('The module is renamed');
        $this->assertSame('Scheduling', DB::table('modules')->where('id', $this->module)->value('title'));
        $this->assertCount(1, $this->auditRows(AuditAction::MCP_DESTRUCTIVE_CONFIRMED));

        $this->post($url)->assertNotFound();
        $this->get($url)->assertNotFound();
    }

    public function test_every_rename_runs_on_its_own_target(): void
    {
        $this->actingAs($this->ada);
        $this->post($this->ask('rename_topic', ['topic_id' => $this->topic, 'new_name' => 'Virtual memory']))->assertOk();
        $this->post($this->ask('rename_course', ['course_id' => $this->course, 'new_name' => 'OS']))->assertOk();
        $this->post($this->ask('rename_note', ['note_id' => $this->note, 'new_title' => 'CPU scheduling']))->assertOk();

        $this->assertSame('Virtual memory', DB::table('topics')->where('id', $this->topic)->value('name'));
        $this->assertSame('OS', DB::table('workspaces')->where('id', $this->course)->value('name'));
        $this->assertSame('CPU scheduling', DB::table('notes')->where('id', $this->note)->value('title'));
    }

    public function test_what_lands_in_every_prompt_asks_for_the_password_first(): void
    {
        $url = $this->ask('remember', ['text' => 'Prefers worked examples.', 'course_id' => $this->course]);

        $this->actingAs($this->ada)->post($url)->assertRedirect(route('password.confirm'));
        $this->assertSame(0, DB::table('tutor_memories')->count());

        $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])->post($url)->assertOk();
        $this->assertSame('Prefers worked examples.', DB::table('tutor_memories')->value('text'));
    }

    public function test_forgetting_and_replacing_a_note_also_wait_for_the_student(): void
    {
        $memory = app(TutorMemories::class)->add($this->by, null, 'Likes diagrams.', 'student')->id;
        $forget = $this->ask('forget_memory', ['memory_id' => $memory]);
        $replace = $this->ask('replace_note', ['note_id' => $this->note, 'text' => 'Everything new.']);

        $this->assertSame(1, DB::table('tutor_memories')->count());
        $this->actingAs($this->ada)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
        $this->post($forget)->assertOk();
        $this->post($replace)->assertOk();

        $this->assertSame(0, DB::table('tutor_memories')->count());
        $this->assertStringContainsString('Everything new.', (string) DB::table('note_versions')->where('note_id', $this->note)->orderByDesc('version')->value('doc'));
    }

    public function test_a_wrong_expired_or_other_students_link_is_not_found(): void
    {
        $url = $this->ask('rename_module', ['module_id' => $this->module, 'new_title' => 'Scheduling']);

        $this->actingAs($this->student())->get($url)->assertNotFound();
        $this->post($url)->assertNotFound();
        $this->actingAs($this->ada)->get(route('connections.confirm', 'nonsense'))->assertNotFound();

        $this->travel(11)->minutes();
        $this->actingAs($this->ada)->get($url)->assertNotFound();
        $this->post($url)->assertNotFound();
        $this->assertSame('Week 2', DB::table('modules')->where('id', $this->module)->value('title'));
    }

    public function test_disconnecting_voids_a_waiting_change(): void
    {
        $url = $this->ask('rename_module', ['module_id' => $this->module, 'new_title' => 'Scheduling']);

        app(Connections::class)->disconnect($this->by, (string) DB::table('mcp_connections')->value('id'));

        $this->actingAs($this->ada)->post($url)->assertNotFound();
        $this->assertSame('Week 2', DB::table('modules')->where('id', $this->module)->value('title'));
    }

    public function test_an_id_that_is_not_the_students_is_refused_before_anything_is_stored(): void
    {
        $mallory = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($mallory, ['name' => 'Chemistry'])->id;

        $this->callTool($this->token, 'rename_course', ['course_id' => $theirs, 'new_name' => 'Mine now'])->assertJsonPath('result.isError', true);
        $this->assertSame(0, DB::table('mcp_confirmations')->count());
    }

    public function test_the_manage_tools_need_the_full_level_and_tell_the_app_only_the_student_can_confirm(): void
    {
        $write = $this->connectApp($this->student(), 'write')['access_token'];
        $this->assertFalse(collect($this->mcp($write, 'tools/list')->json('result.tools'))->pluck('name')->contains('rename_course'));

        $full = collect($this->mcp($this->token, 'tools/list')->json('result.tools'))->keyBy('name');
        $this->assertCount(46, $full);
        $this->assertTrue($full['rename_topic']['_meta']['anthropic/requiresUserInteraction']);
        $this->assertTrue($full['rename_topic']['annotations']['destructiveHint']);
        $this->assertSame(array_keys(Confirmations::TOOLS), array_values(array_intersect(array_keys(Confirmations::TOOLS), $full->keys()->all())));
        $this->assertStringContainsString('only the student can', $this->callTool($this->token, 'rename_module', ['module_id' => $this->module, 'new_title' => 'X'])->json('result.content.0.text'));
    }
}
