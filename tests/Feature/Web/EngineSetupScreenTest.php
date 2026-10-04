<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Setup;
use App\Livewire\Admin\EngineSetup;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The admin area's AI engine page (docs/specs/study-memory.md §6). */
class EngineSetupScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        config(['vistud.engine.key' => '']);
        $this->app->instance(Engine::class, new Fake);
        $this->admin = $this->admin();
    }

    public function test_the_page_walks_the_admin_through_the_key_and_the_defaults(): void
    {
        $this->actingAs($this->admin)->withSession($this->confirmedSession())->get('/admin')->assertOk()->assertSee('AI engine')->assertSee(route('admin.engine'));
        $this->actingAs($this->admin)->withSession($this->confirmedSession())->get('/admin/engine')->assertOk()
            ->assertSee('<title>AI engine · Admin', false)->assertSee('Not set up')->assertSee('Step 1 · Get a key and paste it here')->assertSee('openrouter.ai');

        $this->actingAs($this->admin)->withSession($this->confirmedSession());
        // Livewire's test requests skip middleware, so nothing gives them the session.
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));
        $page = Livewire::test(EngineSetup::class)
            ->set('key', 'nope')->call('saveKey')->assertHasErrors(['key'])
            ->set('key', 'sk-or-v1-abcdefghijklmnopqrstuvwxyz')->call('saveKey')->assertHasNoErrors()
            ->assertSet('key', '')->assertSee('The key is saved and works.')->assertSee('It works: the service offers 3 models.')->assertSee('Key ending …wxyz')->assertSee('Replace the key');
        $this->assertTrue(app(Setup::class)->keySet());

        $page->set('tutorModel', 'fake/tutor')->set('readerModel', 'fake/quick')->set('helperModel', 'fake/plain')->call('saveDefaults')->assertHasNoErrors()->assertSee('The defaults are saved.');
        $this->assertSame(['tutor' => 'fake/tutor', 'reader' => 'fake/quick', 'helper' => 'fake/plain'], app(Setup::class)->defaultModels());
        $page->set('url', 'nonsense')->call('saveDefaults')->assertHasErrors(['url']);

        $page->call('askRemove')->assertSee('Remove the key?')->call('removeKey')->assertSee('The key is removed')->assertSee('Not set up');
        $this->assertFalse(app(Setup::class)->keySet());
    }

    public function test_a_student_is_refused_and_sees_where_the_set_up_is_only_as_an_admin(): void
    {
        $this->actingAs($this->student())->get('/admin/engine')->assertForbidden();
    }
}
