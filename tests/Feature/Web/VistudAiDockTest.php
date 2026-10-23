<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Engine\VistudAi;
use App\Livewire\Study\VistudAiDock;
use App\Study\Sessions;
use App\Study\Workspaces;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** ViStud AI from any student page: the corner button and its panel (the owner's ask, 2026-10-23). */
class VistudAiDockTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_the_button_is_on_student_pages_but_where_a_chat_already_is(): void
    {
        $this->withoutVite();
        $this->app->instance(Engine::class, new Fake);
        $user = $this->student();
        $by = $this->principal($user);
        $course = app(Workspaces::class)->create($by, ['name' => 'Physics']);
        $session = app(Sessions::class)->start($by, $course->id);
        $this->actingAs($user);

        foreach ([route('home'), route('workspaces.show', $course->id), route('settings')] as $url) {
            $this->get($url)->assertOk()->assertSee('ai-dock-fab', false);
        }
        foreach ([route('vistud-ai'), route('workspaces.sessions.show', [$course->id, $session->id])] as $url) {
            $this->get($url)->assertOk()->assertDontSee('ai-dock-fab', false);
        }
    }

    public function test_it_opens_on_the_latest_conversation_and_starts_a_new_one(): void
    {
        $this->withoutVite();
        $this->app->instance(Engine::class, $engine = new Fake);
        $user = $this->student();
        $this->actingAs($user);
        $by = $this->principal($user);
        $this->ownAiKey($by);
        app(Settings::class)->set($by, ['tutor_model' => 'fake/tutor', 'consent' => true]);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        $engine->will(Fake::says('Physics is your next course.', 100, 'fake/tutor'));
        $ai = app(VistudAi::class);
        $id = $ai->start($by, 'What next?');
        $ai->send($by, $id, 'What next?');

        Livewire::test(VistudAiDock::class)
            ->assertSee('ViStud AI')->assertDontSee('Physics is your next course.')
            ->call('show')->assertSet('open', true)->assertSet('conversation', $id)
            ->assertSee('Physics is your next course.')
            ->call('newChat')->assertSet('conversation', null)->assertDontSee('Physics is your next course.')
            // The conversations list: found by its title, opened from it.
            ->call('toggleList')->assertSet('listing', true)->assertSee('What next?')
            ->set('search', 'nothing like it')->assertSee('No conversation is called that.')
            ->set('search', '')->call('pick', $id)->assertSet('listing', false)->assertSet('conversation', $id)->assertSee('Physics is your next course.')
            ->call('hide')->assertSet('open', false);
    }
}
