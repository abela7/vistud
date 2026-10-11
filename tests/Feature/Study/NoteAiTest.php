<?php

namespace Tests\Feature\Study;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Study\Notes;
use App\Study\Workspaces;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * In-note AI assistant tests for highlighted text, attachments, and ephemeral answering.
 */
class NoteAiTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $ada;

    private Principal $by;

    private string $workspaceId;

    private string $noteId;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $this->engine = new Fake;
        $this->app->instance(Engine::class, $this->engine);
        $this->ada = $this->student();
        $this->by = $this->principal($this->ada);
        $this->workspaceId = app(Workspaces::class)->create($this->by, ['name' => 'Cell Biology'])->id;
        $this->noteId = app(Notes::class)->create($this->by, 'workspace', $this->workspaceId, 'Mitosis Notes')->id;

        config(['vistud.engine.key' => 'sk-or-owner-000000000000000']);
        app(Settings::class)->set($this->by, [
            'tutor_model' => 'fake/tutor',
            'reader_model' => 'fake/plain',
            'helper_model' => 'fake/tutor',
            'consent' => true,
        ]);
    }

    public function test_in_note_ai_answers_highlighted_selection(): void
    {
        $this->engine->will(
            Fake::says('Anaphase is when sister chromatids separate.', 1_000, 'fake/tutor')
        );

        $response = $this->actingAs($this->ada)->postJson(
            route('workspaces.notes.ask-ai', ['workspace' => $this->workspaceId]),
            [
                'note_id' => $this->noteId,
                'selection' => 'During anaphase, the sister chromatids are pulled apart.',
                'question' => 'Explain this simply.',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('answer', 'Anaphase is when sister chromatids separate.');
    }

    public function test_in_note_ai_accepts_base64_attachments(): void
    {
        $this->engine->will(
            Fake::says('The attached diagram illustrates chromatid separation.', 1_000, 'fake/tutor')
        );

        $pngBase64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $response = $this->actingAs($this->ada)->postJson(
            route('workspaces.notes.ask-ai', ['workspace' => $this->workspaceId]),
            [
                'note_id' => $this->noteId,
                'selection' => 'Chromosome segregation diagram',
                'question' => 'What does this image show?',
                'attachments' => [
                    [
                        'name' => 'diagram.png',
                        'data' => $pngBase64,
                    ],
                ],
            ]
        );

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('answer', 'The attached diagram illustrates chromatid separation.');

        $this->assertNotEmpty($this->engine->requests);
        $req = $this->engine->requests[0];
        $this->assertNotEmpty($req->messages);
    }

    public function test_in_note_ai_accepts_uploaded_multipart_files(): void
    {
        $this->engine->will(
            Fake::says('The uploaded text file contains spindle fiber details.', 1_000, 'fake/tutor')
        );

        $file = UploadedFile::fake()->createWithContent('spindle.txt', 'Spindle fibers are microtubules.');

        $response = $this->actingAs($this->ada)->post(
            route('workspaces.notes.ask-ai', ['workspace' => $this->workspaceId]),
            [
                'note_id' => $this->noteId,
                'selection' => 'Spindle fibers attach to kinetochores.',
                'question' => 'Explain from file.',
                'attachments' => [$file],
            ],
            ['Accept' => 'application/json']
        );

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('answer', 'The uploaded text file contains spindle fiber details.');

        $this->assertNotEmpty($this->engine->requests);
    }

    public function test_in_note_ai_requires_selected_text(): void
    {
        $response = $this->actingAs($this->ada)->postJson(
            route('workspaces.notes.ask-ai', ['workspace' => $this->workspaceId]),
            [
                'note_id' => $this->noteId,
                'selection' => '',
                'question' => 'Hello?',
            ]
        );

        $response->assertStatus(422)
            ->assertJsonPath('error', 'No text selected.');
    }

    public function test_in_note_ai_answers_with_chosen_model(): void
    {
        $this->engine->will(
            Fake::says('Explanation using chosen model.', 1_000, 'openai/gpt-4o')
        );

        $response = $this->actingAs($this->ada)->postJson(
            route('workspaces.notes.ask-ai', ['workspace' => $this->workspaceId]),
            [
                'note_id' => $this->noteId,
                'selection' => 'Cell division cycle.',
                'question' => 'Explain briefly.',
                'model' => 'openai/gpt-4o',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('answer', 'Explanation using chosen model.')
            ->assertJsonPath('model', 'openai/gpt-4o');

        $this->assertNotEmpty($this->engine->requests);
        $this->assertSame('openai/gpt-4o', $this->engine->requests[0]->model);
    }
}
