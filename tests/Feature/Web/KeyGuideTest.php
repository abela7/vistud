<?php

namespace Tests\Feature\Web;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Livewire\Study\EngineSettings;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Getting your own key step by step, in a side panel of AI settings (the owner's ask, 2026-10-23). */
class KeyGuideTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_the_guide_walks_through_account_credit_key_limit_safety_paste_and_model(): void
    {
        $this->withoutVite();
        $this->app->instance(Engine::class, new Fake);
        $user = $this->student();
        $this->actingAs($user);
        $by = $this->principal($user);
        app(Settings::class)->set($by, ['consent' => true]);

        $page = Livewire::test(EngineSettings::class)
            // The way in, and each step with its link to OpenRouter.
            ->assertSee('New to this? Get your own key step by step')
            ->assertSeeInOrder(['Make an OpenRouter account', 'Add a little credit', 'Create your key', 'Set a spending limit', 'Copy it and keep it safe', 'Paste it here', 'Choose your tutor'])
            ->assertSee('href="https://openrouter.ai"', false)->assertSee('https://openrouter.ai/settings/credits')->assertSee('https://openrouter.ai/settings/keys')
            ->assertSee('Credit limit')->assertSee('password manager')
            // Step 6: the key is saved as an OpenRouter key and tried.
            ->set('source', 'own')->set('key', 'sk-or-v1-'.str_repeat('a', 40))->call('saveGuideKey')->assertHasNoErrors()
            ->assertSet('key', '')->assertSet('source', 'openrouter')->assertSee('is saved and works');
        $this->assertNotNull(app(Settings::class)->key($by));

        // Step 7: a pick becomes the tutor; nothing else changes.
        $page->call('chooseGuideModel', 'google/gemini-2.5-flash')->assertHasNoErrors();
        $choices = app(Settings::class)->get($by);
        $this->assertSame('google/gemini-2.5-flash', $choices->tutorModel);
        $this->assertNotNull($choices->consentedAt);
        $page->assertSee('Step-by-step key guide');
    }

    public function test_a_wrong_key_says_so_in_the_guide(): void
    {
        $this->withoutVite();
        $this->app->instance(Engine::class, new Fake);
        $this->actingAs($this->student());

        Livewire::test(EngineSettings::class)->set('key', 'short')->call('saveGuideKey')->assertHasErrors(['key']);
    }
}
