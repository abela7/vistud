<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Livewire\Workspaces\TutorChat;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Every model chosen for a job is in the chat's model list, then the favourites (the owner's ask, 2026-10-23). */
class ChatModelsTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_the_tutor_reader_helper_and_backup_are_in_the_chat_then_the_favourites_each_once(): void
    {
        $this->withoutVite();
        $this->app->instance(Engine::class, new Fake);
        $user = $this->student();
        $this->actingAs($user);
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, [
            'tutor_model' => 'fake/tutor', 'reader_model' => 'fake/quick', 'helper_model' => 'fake/plain', 'fallback_model' => 'other/backup',
            'favorite_models' => ['other/favourite', 'fake/quick'], 'consent' => true,
        ]);

        $this->assertSame(['fake/tutor', 'fake/quick', 'fake/plain', 'other/backup', 'other/favourite'], app(Settings::class)->get($by)->chatModels());
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        Livewire::test(TutorChat::class, ['vistud' => true])
            ->assertSee("switchModel('fake/quick')", false)->assertSee("switchModel('fake/plain')", false)->assertSee("switchModel('other/favourite')", false);
    }
}
