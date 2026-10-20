<?php

namespace Tests\Feature\Web;

use App\Livewire\Study\TimezoneSettings;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The time zone on the Security part of Settings: shown, chosen from the list, or taken from the browser in one press. */
class TimezoneSettingsScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_student_changes_their_time_zone_from_settings(): void
    {
        $ada = $this->student();
        $this->actingAs($ada);
        $this->app->rebinding('request', fn ($app, $request) => $request->setLaravelSession($app['session.store']));

        // The panel is on the Security part: the zone they have, every zone to choose from, and the browser's zone read on the page.
        $this->get(route('settings', ['part' => 'security']))->assertOk()->assertSeeLivewire(TimezoneSettings::class)
            ->assertSee('Time zone')->assertSee('UTC ·')->assertSee('<option value="Europe/London">', false)->assertSee('resolvedOptions().timeZone', false)->assertSee('Use this');

        Livewire::test(TimezoneSettings::class)->assertSet('timezone', 'UTC')
            ->set('timezone', 'Europe/London')->call('save')->assertHasNoErrors()->assertSee('Your time zone is saved.')->assertSee('Europe/London ·');
        $this->assertSame('Europe/London', DB::table('learners')->where('user_id', $ada->id)->value('timezone'));

        // A name that isn't a zone is refused on the field, and nothing changes.
        Livewire::test(TimezoneSettings::class)->assertSet('timezone', 'Europe/London')
            ->set('timezone', 'Mars/Olympus')->call('save')->assertHasErrors(['timezone'])->assertSee('Choose a time zone from the list.');
        $this->assertSame('Europe/London', DB::table('learners')->where('user_id', $ada->id)->value('timezone'));

        // "Use this" keeps the browser's zone in one press.
        Livewire::test(TimezoneSettings::class)->call('useDetected', 'Asia/Tokyo')->assertHasNoErrors()->assertSet('timezone', 'Asia/Tokyo')->assertSee('Your time zone is saved.');
        $this->assertSame('Asia/Tokyo', DB::table('learners')->where('user_id', $ada->id)->value('timezone'));
    }

    public function test_an_account_without_a_learner_has_no_time_zone_panel(): void
    {
        $this->actingAs($this->admin(student: false));

        $this->get(route('settings', ['part' => 'security']))->assertOk()->assertSee('Two-step sign-in')->assertDontSeeLivewire(TimezoneSettings::class);
    }
}
