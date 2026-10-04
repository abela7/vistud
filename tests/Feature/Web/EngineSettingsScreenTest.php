<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Engine\Setup;
use App\Livewire\Study\EngineSettings;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** A student's AI engine page: their own key, their models, limits and consent (docs/specs/study-memory.md §6). */
class EngineSettingsScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        config(['vistud.engine.key' => '']);
        $this->app->instance(Engine::class, new Fake);
    }

    public function test_a_student_sets_the_chat_up_alone_key_models_limits_and_consent(): void
    {
        $ada = $this->student();
        $by = $this->principal($ada);
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $this->actingAs($ada);

        // The way in: the Overview's menu and the account menu.
        $this->get(route('workspaces.show', $workspace->id))->assertOk()->assertSee(route('engine.settings'))->assertSeeInOrder(['Instructions for the AI', 'AI engine', 'Log time']);
        $this->get('/')->assertOk()->assertSee(route('engine.settings'));
        $this->get('/ai-engine')->assertOk()->assertSee('<title>AI engine', false)->assertSee('Not set up')->assertSee('openrouter.ai')->assertSee('Save and try')->assertDontSee(route('admin.engine'));

        $page = Livewire::test(EngineSettings::class)->assertSet('sessionCap', '2.00')->assertSet('monthCap', '20.00')->assertSet('consent', false)
            ->set('key', 'nope')->call('saveKey')->assertHasErrors(['key'])
            ->set('key', 'sk-or-v1-abcdefghijklmnopqrstuvwxyz')->call('saveKey')->assertHasNoErrors()->assertSet('key', '')
            ->assertSee('Your key is saved and works.')->assertSee('It works: the service offers 3 models.')->assertSee('Your key ending …wxyz')->assertSee('Replace your key')
            // The models are offered with their prices once a key works.
            ->assertSee('Fake tutor · $1.00 in · $5.00 out per million tokens');
        $this->assertSame('sk-or-v1-abcdefghijklmnopqrstuvwxyz', app(Settings::class)->key($by));

        $page->set('tutorModel', 'bad id!')->call('save')->assertHasErrors(['tutorModel']);
        $page->set('tutorModel', 'fake/plain')->assertSee('no look-ups: this model can\'t call tools')
            ->set('tutorModel', 'fake/tutor')->set('quickModel', 'fake/quick')->set('sessionCap', '1.5')->set('consent', true)
            ->call('save')->assertHasNoErrors()->assertSee('Your AI engine settings are saved.');
        $choices = app(Settings::class)->get($by);
        $this->assertSame(['fake/tutor', 'fake/quick', 1_500_000, true, true], [$choices->tutorModel, $choices->quickModel, $choices->sessionCapMicros, $choices->ready(), $choices->ownKey()]);

        $page->call('askRemove')->assertSee('Remove your key?')->call('removeKey')->assertSee('Your key is removed.')->assertSee('Not set up');
        $this->assertNull(app(Settings::class)->key($by));
    }

    public function test_with_the_owners_key_the_chat_works_already_and_an_admin_is_pointed_to_the_shared_set_up(): void
    {
        $admin = $this->admin();
        app(Setup::class)->setKey($this->principal($admin), 'sk-or-v1-owner-key-00000000000');
        $this->actingAs($this->student());
        Livewire::test(EngineSettings::class)->assertSee("Using the owner's", false)->assertSee('Paste your own below to pay for your own chats instead')->assertDontSee(route('admin.engine'));

        app(Setup::class)->removeKey($this->principal($admin));
        $this->actingAs($admin);
        Livewire::test(EngineSettings::class)->assertSee('Not set up')->assertSee(route('admin.engine'));
    }
}
