<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Turning on two-factor authentication (WP6; ADR 0003 §10.3). Fortify handles every POST. */
class TwoFactorSetupScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/user/two-factor')->assertRedirect(route('login'));
    }

    public function test_a_fresh_password_confirmation_comes_first(): void
    {
        $this->actingAs($this->student())->get('/user/two-factor')->assertRedirect(route('password.confirm'));
    }

    public function test_the_whole_setup_shows_recovery_codes_exactly_once(): void
    {
        $user = $this->student();
        $this->actingAs($user)->withSession($this->confirmedSession());

        $this->get('/user/two-factor')->assertOk()
            ->assertSee('Turn on two-factor authentication')
            ->assertSee('action="'.route('two-factor.enable').'"', false);

        $this->from('/user/two-factor')->post('/user/two-factor-authentication')->assertRedirect('/user/two-factor');

        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $this->get('/user/two-factor')->assertOk()
            ->assertSee('Set up your authenticator app')
            ->assertSee('fill="currentColor"', false)
            ->assertSee(trim(chunk_split($secret, 4, ' ')))
            ->assertSee('action="'.route('two-factor.confirm').'"', false)
            ->assertDontSee('Save your recovery codes');

        $this->from('/user/two-factor')->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])
            ->assertRedirect('/user/two-factor');
        $this->get('/user/two-factor')->assertSee('The provided two factor authentication code was invalid.')
            ->assertSee('aria-invalid="true"', false);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);

        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->from('/user/two-factor')->post('/user/confirmed-two-factor-authentication', ['code' => $code])
            ->assertRedirect('/user/two-factor');
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        $codes = $user->fresh()->recoveryCodes();
        $first = $this->get('/user/two-factor')->assertOk()->assertSee('Save your recovery codes');
        foreach ($codes as $recoveryCode) {
            $first->assertSee($recoveryCode);
        }

        $this->get('/user/two-factor')->assertOk()
            ->assertSee('Two-factor authentication is on')
            ->assertDontSee($codes[0]);
        $this->getJson('/user/two-factor-recovery-codes')->assertForbidden();
    }

    public function test_new_recovery_codes_are_shown_once(): void
    {
        $user = User::factory()->student()->twoFactor()->create()->fresh();
        $this->actingAs($user)->withSession($this->confirmedSession());

        $this->get('/user/two-factor')->assertOk()->assertSee('Generate new recovery codes')->assertDontSee('code-one-aaaa');

        $this->from('/user/two-factor')->post('/user/two-factor-recovery-codes')->assertRedirect('/user/two-factor');
        $codes = $user->fresh()->recoveryCodes();
        $this->assertNotContains('code-one-aaaa', $codes);

        $this->get('/user/two-factor')->assertSee($codes[0]);
        $this->get('/user/two-factor')->assertDontSee($codes[0]);
    }

    public function test_cancelling_setup_turns_it_back_off(): void
    {
        $user = $this->student();
        $this->actingAs($user)->withSession($this->confirmedSession());
        $this->from('/user/two-factor')->post('/user/two-factor-authentication');

        $this->from('/user/two-factor')->delete('/user/two-factor-authentication')->assertRedirect('/user/two-factor');

        $this->assertNull($user->fresh()->two_factor_secret);
        $this->get('/user/two-factor')->assertSee('Turn on two-factor authentication');
    }

    public function test_an_admin_without_two_factor_is_sent_here_from_the_admin_area(): void
    {
        $admin = User::factory()->admin()->student()->create()->fresh();

        $this->actingAs($admin)->withSession($this->confirmedSession())->get('/admin')
            ->assertRedirect(route('two-factor.setup'));
    }

    public function test_the_account_menu_links_to_the_setup(): void
    {
        $this->actingAs($this->student())->get('/')
            ->assertSee('href="'.route('two-factor.setup').'" class="menu-item"', false);
    }
}
