<?php

namespace Tests\Feature\Web;

use App\Mcp\NoteApps;
use App\Models\User;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Topics;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * A note edited in the editor and from a chat app at once (docs/specs/mcp-connections-plan.md §3.7, M6.3): the app's
 * version says it is the app's, the page can ask who wrote the newest version, a stale save from the editor gets 409 with
 * both texts kept, and the page polls only while an app is working.
 */
class NoteConflictTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private string $token;

    private string $course;

    private string $session;

    private string $note;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00:00', 'UTC'));
        $this->ada = $this->student();
        $by = $this->principal($this->ada);
        $this->course = app(Workspaces::class)->create($by, ['name' => 'Operating systems'])->id;
        $module = app(Modules::class)->create($by, $this->course, ['title' => 'Week 2: Scheduling'])->id;
        $topic = app(Topics::class)->create($by, $this->course, 'Round robin', $module)->id;
        $this->note = app(Notes::class)->create($by, 'module', $module, 'Scheduling')->id;
        $this->token = $this->connectApp($this->ada, 'write')['access_token'];
        $this->session = (string) $this->callTool($this->token, 'start_session', ['course_id' => $this->course, 'topic_id' => $topic])->json('result.structuredContent.session_id');
    }

    private function appWrites(string $text = 'Added by the chat app.'): void
    {
        $this->callTool($this->token, 'write_note', ['session_id' => $this->session, 'note' => 'Scheduling', 'text' => $text])->assertJsonPath('result.structuredContent.status', 'recorded');
    }

    private function save(int $base, string $text, string $saveId, string $kind = 'autosave'): array
    {
        return [
            'base_version' => $base, 'save_id' => $saveId, 'client_id' => 'tab-00001', 'kind' => $kind, 'title' => 'Scheduling',
            'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]],
        ];
    }

    private function pulse(): array
    {
        return $this->actingAs($this->ada)->getJson(route('workspaces.notes.pulse', [$this->course, $this->note]))->assertOk()->json();
    }

    public function test_the_apps_edit_is_a_version_of_its_own_kind(): void
    {
        $this->appWrites();

        $this->assertSame('chat_app', DB::table('note_versions')->where('note_id', $this->note)->orderByDesc('version')->value('kind'));
    }

    public function test_the_pulse_names_the_app_only_while_its_edit_is_the_newest_version(): void
    {
        $this->assertSame(['version' => 1, 'app' => null, 'active' => true], $this->pulse());

        $this->appWrites();
        $this->assertSame(['version' => 2, 'app' => 'Anthropic', 'active' => true], $this->pulse());

        $this->putJson("/api/v1/notes/{$this->note}", $this->save(2, 'Then I wrote this.', 'save-0001'))->assertOk();
        $this->assertSame(['version' => 3, 'app' => null, 'active' => true], $this->pulse());
    }

    public function test_an_app_unused_for_ten_minutes_is_not_active(): void
    {
        $this->travel(11)->minutes();

        $this->assertFalse($this->pulse()['active']);
        $this->assertFalse(app(NoteApps::class)->active($this->principal($this->ada)));
    }

    public function test_a_stale_save_from_the_editor_is_a_conflict_and_both_texts_are_kept(): void
    {
        $this->actingAs($this->ada);
        $this->putJson("/api/v1/notes/{$this->note}", $this->save(1, 'My first words.', 'save-0001'))->assertOk();
        $this->appWrites('The app added this.');

        $this->putJson("/api/v1/notes/{$this->note}", $this->save(2, 'My later words.', 'save-0002'))
            ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict')->assertJsonPath('error.details.current_version', 3);
        $this->assertSame(3, DB::table('notes')->where('id', $this->note)->value('current_version'));

        $this->putJson("/api/v1/notes/{$this->note}", $this->save(3, 'My later words, and the app added this.', 'save-0003', 'conflict_resolution'))->assertOk();

        $texts = LearnerTables::query(LearnerScope::of($this->principal($this->ada)), 'note_versions')->where('note_id', $this->note)->orderBy('version')->pluck('doc')->implode(' ');
        $this->assertStringContainsString('My first words.', $texts);
        $this->assertStringContainsString('The app added this.', $texts);
        $this->assertStringContainsString('My later words', $texts);
    }

    public function test_the_page_polls_only_while_an_app_is_working_and_has_a_place_to_name_it(): void
    {
        $this->actingAs($this->ada)->get(route('workspaces.notes.show', [$this->course, $this->note]))
            ->assertOk()
            ->assertSee('data-poll', false)
            ->assertSee(route('workspaces.notes.pulse', [$this->course, $this->note]), false)
            ->assertSee('data-conflict-app', false);

        $this->travel(11)->minutes();
        $this->actingAs($this->ada)->get(route('workspaces.notes.show', [$this->course, $this->note]))
            ->assertOk()->assertDontSee('data-poll', false);
    }

    public function test_another_student_cannot_ask_after_the_note(): void
    {
        $this->actingAs($this->student())->getJson(route('workspaces.notes.pulse', [$this->course, $this->note]))->assertNotFound();
        $this->actingAs($this->ada)->getJson(route('workspaces.notes.pulse', ['other-course', $this->note]))->assertNotFound();
    }
}
