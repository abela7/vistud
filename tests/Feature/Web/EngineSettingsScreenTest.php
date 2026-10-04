<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Livewire\Workspaces\EngineSettings;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The AI engine dialog on a workspace's Overview (docs/specs/study-memory.md §6). */
class EngineSettingsScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_the_models_are_offered_with_their_prices_and_the_choices_are_saved(): void
    {
        Cache::flush();
        $this->app->instance(Engine::class, new Fake);
        config(['vistud.engine.key' => 'set']);
        $ada = $this->student();
        $by = $this->principal($ada);
        $workspace = app(Workspaces::class)->create($by, ['name' => 'Databases']);
        $this->actingAs($ada);

        $this->get(route('workspaces.show', $workspace->id))->assertOk()->assertSeeInOrder(['Instructions for the AI', 'AI engine', 'Log time']);

        $dialog = Livewire::test(EngineSettings::class)->assertSet('editing', false)->call('edit')->assertSet('editing', true)->assertDispatched('engine-settings-dialog-open')
            ->assertSee('Fake tutor · $1.00 in · $5.00 out per million tokens')->assertSee('Fake plain')->assertSet('sessionCap', '2.00')->assertSet('monthCap', '20.00')->assertSet('consent', false);

        $dialog->set('tutorModel', 'bad id!')->call('save')->assertHasErrors(['tutorModel'])->assertSet('editing', true);
        $dialog->set('tutorModel', 'fake/plain')->assertSee('no look-ups: this model can\'t call tools')
            ->set('tutorModel', 'fake/tutor')->set('quickModel', 'fake/quick')->set('sessionCap', '1.5')->set('consent', true)
            ->call('save')->assertHasNoErrors()->assertDispatched('engine-settings-dialog-close')->assertSee('AI engine settings saved.');

        $choices = app(Settings::class)->get($by);
        $this->assertSame(['fake/tutor', 'fake/quick', 1_500_000, true], [$choices->tutorModel, $choices->quickModel, $choices->sessionCapMicros, $choices->ready()]);
    }

    public function test_without_a_key_the_dialog_says_so(): void
    {
        config(['vistud.engine.key' => '']);
        $this->actingAs($this->student());
        Livewire::test(EngineSettings::class)->call('edit')->assertSee('No engine key yet')->assertSee('VISTUD_ENGINE_KEY');
    }
}
