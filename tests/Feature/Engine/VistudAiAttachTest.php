<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Engine\VistudAi;
use App\Livewire\Workspaces\TutorChat;
use App\Platform\Access\Principal;
use App\Platform\Errors\Unprocessable;
use App\Study\Files;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * ViStud AI attaches as the tutor's chat does (the owner's ask, 2026-10-22): notes and files from any of the student's
 * courses, and files uploaded or pasted with a message, which go with that message only and into no course.
 */
class VistudAiAttachTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private Fake $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->app->instance(Engine::class, $this->engine = new Fake);
    }

    public function test_a_note_from_any_course_and_an_uploaded_document_go_with_the_message_and_stay_for_later_turns(): void
    {
        $by = $this->ready();
        $physics = app(Workspaces::class)->create($by, ['name' => 'Physics']);
        $note = app(Notes::class)->createWritten($by, 'workspace', $physics->id, ['title' => 'Kinematics', 'create_id' => 'kinematics01', 'doc' => self::doc('Velocity is the rate of change of position.')]);
        $ai = app(VistudAi::class);
        $id = $ai->start($by, 'Help');

        $ai->send($by, $id, 'Explain these', attach: ["note:{$note->id}"], uploads: ['k1' => ['name' => 'Lecture.txt', 'bytes' => "Newton's second law: F = ma.\n"]]);
        $sent = json_encode($this->engine->last()->messages);
        $this->assertStringContainsString('Velocity is the rate of change of position.', $sent);
        $this->assertStringContainsString("Newton's second law: F = ma.", $sent);

        // Later turns read them too (kept with the message), and the chat shows them, the note opening in its course.
        $ai->send($by, $id, 'And again?');
        $this->assertStringContainsString("Newton's second law", json_encode($this->engine->last()->messages));
        $attached = $ai->transcript($by, $id)[0]['attachments'];
        $this->assertSame(['note', 'file'], array_column($attached, 'kind'));
        $this->assertSame($physics->id, $attached[0]['course']);
        // The upload is in no course.
        $this->assertSame([], app(Files::class)->list($by, $physics->id));
    }

    public function test_an_uploaded_picture_is_shown_with_its_answer_only(): void
    {
        $by = $this->ready();
        $ai = app(VistudAi::class);
        $id = $ai->start($by, 'Look');
        $png = UploadedFile::fake()->image('board.png', 40, 30)->getContent();

        $ai->send($by, $id, 'What is on this board?', uploads: ['p1' => ['name' => 'board.png', 'bytes' => $png]]);
        $this->assertStringContainsString('data:image/', json_encode($this->engine->last()->messages, JSON_UNESCAPED_SLASHES));
        $ai->send($by, $id, 'Thanks');
        $later = json_encode($this->engine->last()->messages, JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('data:image/', $later);
        $this->assertStringContainsString('Attached earlier: the picture', $later);
        // In the chat it is its name and an icon: it was kept nowhere, so there is no picture to show.
        Livewire::test(TutorChat::class, ['vistud' => true, 'conversationId' => $id])
            ->assertSee('board.png')->assertDontSee('alt="board.png"', false);
    }

    public function test_a_file_that_cant_be_read_or_too_many_are_refused_before_anything_is_kept(): void
    {
        $by = $this->ready();
        $ai = app(VistudAi::class);
        $id = $ai->start($by, 'Help');
        foreach ([
            ['x' => ['name' => 'tool.exe', 'bytes' => 'MZ']],
            array_fill_keys(['a', 'b', 'c', 'd', 'e'], ['name' => 'n.txt', 'bytes' => 'words']),
        ] as $uploads) {
            try {
                $ai->send($by, $id, 'Read', uploads: $uploads);
                $this->fail('Accepted');
            } catch (Unprocessable) {
            }
        }
        $this->assertSame([], $ai->transcript($by, $id));
    }

    public function test_the_chat_uploads_with_the_message_and_lists_notes_from_every_course(): void
    {
        $by = $this->ready();
        $biology = app(Workspaces::class)->create($by, ['name' => 'Biology']);
        app(Notes::class)->createWritten($by, 'workspace', $biology->id, ['title' => 'Cells', 'create_id' => 'cells000001', 'doc' => self::doc('The cell is the unit of life.')]);
        $this->engine->will(Fake::says('Read it.', 100, 'fake/tutor'));

        Livewire::test(TutorChat::class, ['vistud' => true])
            ->assertSee('Add file, photo or note')->assertSee('Cells · Biology')
            ->set('uploads.k9', UploadedFile::fake()->createWithContent('summary.md', "# Photosynthesis\nLight makes sugar."))
            ->call('send', 'Read my summary', ['upload:k9'])
            ->assertSet('uploads', []);
        $this->assertStringContainsString('Light makes sugar.', json_encode($this->engine->last()->messages));
    }

    /** @return array<string, mixed> a note's body with one paragraph */
    private static function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    private function ready(): Principal
    {
        $user = $this->student(['name' => 'Ada Lovelace']);
        $this->actingAs($user);
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        return $by;
    }
}
