<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Engine\Usage;
use App\Engine\VistudAi;
use App\Livewire\Study\VistudAiPage;
use App\Livewire\Workspaces\TutorChat;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Study\TutorMemories;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Clearing a ViStud AI chat empties it and keeps what ViStud AI remembered about the student; a long chat's summarised
 * part is marked in the chat (the owner's ask, 2026-10-22).
 */
class ChatClearAndFoldTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_clearing_a_chat_empties_it_keeps_the_conversation_and_what_was_remembered(): void
    {
        $this->withoutVite();
        $this->app->instance(Engine::class, $engine = new Fake);
        $user = $this->student(['name' => 'Ada Lovelace']);
        $this->actingAs($user);
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        $engine->will(Fake::says('Hello Ada.', 1_000, 'fake/tutor'));
        $id = app(VistudAi::class)->start($by, 'Hi, I study medicine.');
        app(VistudAi::class)->send($by, $id, 'Hi, I study medicine.');
        app(TutorMemories::class)->add($by, null, 'Studies medicine at Addis Ababa University.', 'tutor');
        $this->assertNotSame([], app(VistudAi::class)->transcript($by, $id));

        Livewire::withQueryParams(['c' => $id])->test(VistudAiPage::class)
            ->assertSee('Clear this chat')
            ->call('clear')->assertHasNoErrors();

        $this->assertSame([], app(VistudAi::class)->transcript($by, $id));
        $this->assertContains($id, array_column(app(VistudAi::class)->conversations($by), 'id'), 'the conversation stays');
        $this->assertSame(['Studies medicine at Addis Ababa University.'], app(TutorMemories::class)->lines($by, null));
        // What it cost stays counted.
        $this->assertGreaterThan(0, app(Usage::class)->month($by)['total']);

        // It goes on in the same conversation.
        $engine->will(Fake::says('Welcome back.', 500, 'fake/tutor'));
        app(VistudAi::class)->send($by, $id, 'Hello again');
        $this->assertCount(2, app(VistudAi::class)->transcript($by, $id));
    }

    public function test_the_summarised_part_of_a_long_chat_is_marked(): void
    {
        $this->withoutVite();
        $this->app->instance(Engine::class, $engine = new Fake);
        $user = $this->student();
        $this->actingAs($user);
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        $engine->will(Fake::says('One.', 100, 'fake/tutor'), Fake::says('Two.', 100, 'fake/tutor'));
        $id = app(VistudAi::class)->start($by, 'First');
        app(VistudAi::class)->send($by, $id, 'First');
        Livewire::test(TutorChat::class, ['vistud' => true, 'conversationId' => $id])->assertDontSee('Earlier messages are summarised');

        app(VistudAi::class)->send($by, $id, 'Second');
        LearnerTables::query(LearnerScope::of($by), 'engine_threads')->where('id', $id)->update(['summary' => 'They said first.', 'folded_through' => 2]);
        Livewire::test(TutorChat::class, ['vistud' => true, 'conversationId' => $id])
            ->assertSee('Earlier messages are summarised for the AI, so the chat can keep going.')->assertSee('First')->assertSee('Two.');
    }
}
