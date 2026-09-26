<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Platform\Http\Middleware\AddAccountHeader;
use App\Study\Notes;
use App\Study\Workspaces;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\Support\OpenApi;
use Tests\TestCase;

/** GET and PUT /api/v1/notes/{id}: the editor's reads and autosaves (ADR 0003 §5.3). */
class NotesApiTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private string $noteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ada = $this->student();
        $by = $this->principal($this->ada);
        $biology = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $this->noteId = app(Notes::class)->create($by, 'workspace', $biology->id, 'Mitosis')->id;
    }

    public function test_the_editor_reads_and_saves_a_note(): void
    {
        $spec = new OpenApi;
        $path = '/api/v1/notes/{id}';

        $read = $this->actingAs($this->ada)->getJson("/api/v1/notes/{$this->noteId}")
            ->assertOk()->assertHeader(AddAccountHeader::HEADER, $this->ada->id)
            ->assertJsonPath('version', 1)->assertJsonPath('title', 'Mitosis');
        $this->assertSame([], $spec->validateResponse('GET', $path, 200, $read->json()));

        // Spaces around formatted words are kept exactly as typed.
        $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Makes '], ['type' => 'text', 'text' => 'two', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => ' cells. '],
        ]]]];
        $saved = $this->putJson("/api/v1/notes/{$this->noteId}", $this->save(1, $doc, 'save-0001'))->assertOk()->assertJsonPath('version', 2);
        $this->assertSame([], $spec->validateResponse('PUT', $path, 200, $saved->json()));
        $this->getJson("/api/v1/notes/{$this->noteId}")->assertJsonPath('doc', $doc)->assertJsonPath('title', 'Mitosis and meiosis');
    }

    public function test_conflicts_trash_and_bad_input_have_their_own_answers(): void
    {
        $spec = new OpenApi;
        $this->actingAs($this->ada)->putJson("/api/v1/notes/{$this->noteId}", $this->save(1, null, 'save-0001'))->assertOk();

        $conflict = $this->putJson("/api/v1/notes/{$this->noteId}", $this->save(1, null, 'save-0002'))
            ->assertStatus(409)->assertJsonPath('error.code', 'version_conflict')->assertJsonPath('error.details.current_version', 2);
        $this->assertSame([], $spec->validateResponse('PUT', '/api/v1/notes/{id}', 409, $conflict->json()));

        $this->putJson("/api/v1/notes/{$this->noteId}", ['base_version' => 2, 'save_id' => 'x', 'doc' => ['type' => 'iframe']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['save_id', 'client_id']]]]);

        app(Notes::class)->trash($this->principal($this->ada), $this->noteId);
        $this->putJson("/api/v1/notes/{$this->noteId}", $this->save(2, null, 'save-0003'))->assertStatus(410)->assertJsonPath('error.details.reason', 'trashed');
        $this->getJson("/api/v1/notes/{$this->noteId}")->assertStatus(410);
    }

    public function test_another_learners_note_is_the_same_404_as_a_missing_one(): void
    {
        $bob = $this->student();

        $theirs = $this->actingAs($bob)->putJson("/api/v1/notes/{$this->noteId}", $this->save(1, null, 'save-0001'))->assertNotFound();
        $missing = $this->actingAs($bob)->putJson('/api/v1/notes/no-such-note', $this->save(1, null, 'save-0001'))->assertNotFound();
        $this->assertSame($missing->json('error.code'), $theirs->json('error.code'));
        $this->getJson("/api/v1/notes/{$this->noteId}")->assertNotFound();

        $this->assertSame(1, app(Notes::class)->find($this->principal($this->ada), $this->noteId)->version);

        $this->app['auth']->forgetGuards();
        $this->putJson("/api/v1/notes/{$this->noteId}", $this->save(1, null, 'save-0002'))->assertUnauthorized();
    }

    private function save(int $base, ?array $doc, string $saveId): array
    {
        return [
            'base_version' => $base, 'save_id' => $saveId, 'client_id' => 'tab-00001', 'title' => 'Mitosis and meiosis',
            'doc' => $doc ?? ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "Saved as {$saveId}."]]]]],
        ];
    }
}
