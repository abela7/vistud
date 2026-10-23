<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Model;
use App\Engine\Settings;
use App\Engine\Setup;
use App\Engine\Tools\Context;
use App\Engine\Vistud\OwnKeyAnswerTool;
use App\Engine\VistudAi;
use App\Livewire\Study\EngineSettings;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * ViStud AI offers a student on the shared key a key of their own, once, and keeps the answer: yes gives the guide's
 * link, no is never asked again, later is asked again in a few days (the owner's ask, 2026-10-23).
 */
class OwnKeyOfferTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private Principal $by;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['vistud.engine.key' => 'sk-or-admin-'.bin2hex(random_bytes(8))]);
        $this->app->instance(Engine::class, new Fake([new Model('fake/quick', 'Fake quick', 0.1, 0.4, 32_000, true, false, false)]));
        app(Setup::class)->setShared($this->principal($this->admin()), ['on' => true, 'key' => 'sk-or-students-'.bin2hex(random_bytes(8)), 'models' => 'fake/quick', 'daily' => '0.10']);
        $user = $this->student();
        $this->actingAs($user);
        $this->by = $this->principal($user);
        app(Settings::class)->consent($this->by);
    }

    public function test_a_student_on_the_shared_key_is_asked_until_they_answer_and_no_is_never_asked_again(): void
    {
        $ai = app(VistudAi::class);
        $this->assertStringContainsString('## Their AI key', $ai->system($this->by));
        $this->assertStringContainsString('own_key_answer', $ai->system($this->by));

        $this->tool('no');
        $this->assertStringNotContainsString('## Their AI key', app(VistudAi::class)->system($this->by));
        $this->travel(30)->days();
        $this->assertStringNotContainsString('## Their AI key', app(VistudAi::class)->system($this->by));
    }

    public function test_later_is_asked_again_after_a_few_days_and_yes_gives_the_guides_link(): void
    {
        $this->assertStringContainsString('in a few days', $this->tool('later'));
        $this->assertFalse(app(Settings::class)->shouldOfferOwnKey($this->by));
        $this->travel(Settings::OFFER_AGAIN_DAYS + 1)->days();
        $this->assertTrue(app(Settings::class)->shouldOfferOwnKey($this->by));

        $said = $this->tool('yes');
        $this->assertStringContainsString(route('settings', ['part' => 'ai', 'guide' => 'key']), $said);
        $this->assertStringContainsString('spending limit', $said);
    }

    public function test_never_offered_with_a_key_of_their_own_or_to_an_admin(): void
    {
        $admin = $this->principal($this->admin());
        $this->assertFalse(app(Settings::class)->shouldOfferOwnKey($admin));
        $this->assertStringNotContainsString('## Their AI key', app(VistudAi::class)->system($admin));

        $this->ownAiKey($this->by);
        $this->assertFalse(app(Settings::class)->shouldOfferOwnKey($this->by));
        $this->assertStringContainsString('already have a key', $this->tool('yes'));
        $this->assertNull(LearnerTables::query(LearnerScope::of($this->by), 'engine_settings')->value('own_key_offer'));
    }

    public function test_the_link_opens_the_guide_at_once(): void
    {
        Livewire::withQueryParams(['guide' => 'key'])->test(EngineSettings::class)
            ->assertSee("new URLSearchParams(location.search).get('guide') === 'key'", false);
        $this->get(route('settings', ['part' => 'ai', 'guide' => 'key']))->assertOk()->assertSee('Make an OpenRouter account');
    }

    private function tool(string $answer): string
    {
        return app(OwnKeyAnswerTool::class)->run($this->by, new Context('', null, null, 'UTC'), ['answer' => $answer]);
    }
}
