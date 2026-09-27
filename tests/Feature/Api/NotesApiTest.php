<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Platform\Http\Middleware\AddAccountHeader;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    private string $biologyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ada = $this->student();
        $by = $this->principal($this->ada);
        $biology = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        $this->biologyId = $biology->id;
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

    public function test_deletion_records_are_read_after_a_cursor(): void
    {
        $notes = app(Notes::class);
        $by = $this->principal($this->ada);
        $notes->trash($by, $this->noteId);
        $notes->restore($by, $this->noteId);
        $notes->trash($by, $this->noteId);
        $notes->destroy($by, $this->noteId);
        // Another learner's deletions never show.
        $bob = $this->principal($this->student());
        $theirs = app(Workspaces::class)->create($bob, ['name' => 'Private']);
        $notes->trash($bob, $notes->create($bob, 'workspace', $theirs->id)->id);

        $all = $this->actingAs($this->ada)->getJson('/api/v1/sync/tombstones')->assertOk();
        $this->assertSame([], (new OpenApi)->validateResponse('GET', '/api/v1/sync/tombstones', 200, $all->json()));
        $this->assertSame(['trashed', 'restored', 'trashed', 'deleted'], array_column($all->json('data'), 'kind'));
        $this->assertSame([$this->noteId], array_values(array_unique(array_column($all->json('data'), 'entity_id'))));

        $cursor = $all->json('data.1.cursor');
        $this->getJson("/api/v1/sync/tombstones?since={$cursor}")->assertJsonCount(2, 'data')->assertJsonPath('next_since', $all->json('next_since'))->assertJsonPath('more', false);
        $this->getJson('/api/v1/sync/tombstones?since=-1')->assertStatus(422);
        $this->actingAs($this->admin(student: false))->getJson('/api/v1/sync/tombstones')->assertForbidden();
    }

    public function test_a_new_note_is_made_by_its_first_words_and_never_while_empty(): void
    {
        $spec = new OpenApi;
        $by = $this->principal($this->ada);
        $cells = app(Modules::class)->create($by, $this->biologyId, ['title' => 'Cells']);
        $empty = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
        $words = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Cells divide.']]]]];
        $before = count(app(Notes::class)->list($by, $this->biologyId));

        // Nothing written: nothing kept.
        $this->actingAs($this->ada)->postJson('/api/v1/notes', ['place' => ['type' => 'module', 'id' => $cells->id], 'create_id' => 'new-note-0001', 'title' => '   ', 'doc' => $empty])
            ->assertStatus(422)->assertJsonPath('error.details.fields.doc.0', 'Write a title or some text first.');
        $this->postJson('/api/v1/notes', ['place' => ['type' => 'module', 'id' => $cells->id], 'title' => 'Cells', 'doc' => $empty])->assertStatus(422);
        $this->postJson('/api/v1/notes', ['create_id' => 'new-note-0001', 'title' => 'Cells', 'doc' => $empty])->assertStatus(422);
        $this->assertCount($before, app(Notes::class)->list($by, $this->biologyId));

        // A title alone is enough, and a retry of the same first save makes no second note.
        $made = $this->postJson('/api/v1/notes', ['place' => ['type' => 'module', 'id' => $cells->id], 'create_id' => 'new-note-0002', 'title' => 'Cells', 'doc' => $empty])
            ->assertCreated()->assertJsonPath('version', 1);
        $this->assertSame([], $spec->validateResponse('POST', '/api/v1/notes', 201, $made->json()));
        $this->assertSame(route('workspaces.notes.show', [$this->biologyId, $made->json('id')]), $made->json('url'));
        $this->postJson('/api/v1/notes', ['place' => ['type' => 'module', 'id' => $cells->id], 'create_id' => 'new-note-0002', 'title' => 'Cells', 'doc' => $empty])
            ->assertCreated()->assertJsonPath('id', $made->json('id'));
        $this->assertCount($before + 1, app(Notes::class)->list($by, $this->biologyId));
        $this->assertSame($cells->id, app(Notes::class)->find($by, $made->json('id'))->moduleId);

        // Words alone too, with the words as the first version; then autosave goes on from it.
        $second = $this->postJson('/api/v1/notes', ['place' => ['type' => 'workspace', 'id' => $this->biologyId], 'create_id' => 'new-note-0003', 'title' => '', 'doc' => $words])->assertCreated();
        $this->getJson("/api/v1/notes/{$second->json('id')}")->assertJsonPath('doc', $words)->assertJsonPath('version', 1);
        $this->putJson($second->json('save_url'), $this->save(1, null, 'save-0100'))->assertOk()->assertJsonPath('version', 2);

        // Another student's place is missing.
        $bob = $this->student();
        $theirs = app(Workspaces::class)->create($this->principal($bob), ['name' => 'Theirs']);
        $this->postJson('/api/v1/notes', ['place' => ['type' => 'workspace', 'id' => $theirs->id], 'create_id' => 'new-note-0004', 'title' => 'Mine?', 'doc' => $empty])->assertNotFound();
    }

    public function test_note_images_can_be_uploaded_and_served_to_owner(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->image('diagram.png', 400, 300);

        $upload = $this->actingAs($this->ada)->post('/api/v1/notes/images', [
            'image' => $file,
        ])->assertCreated()->assertJsonStructure(['id', 'url']);

        $id = $upload->json('id');
        $this->get("/notes/images/{$id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        // Another student cannot view it
        $bob = $this->student();
        $this->actingAs($bob)->get("/notes/images/{$id}")->assertNotFound();
    }

    private function save(int $base, ?array $doc, string $saveId): array
    {
        return [
            'base_version' => $base, 'save_id' => $saveId, 'client_id' => 'tab-00001', 'title' => 'Mitosis and meiosis',
            'doc' => $doc ?? ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "Saved as {$saveId}."]]]]],
        ];
    }
}
