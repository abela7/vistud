<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The two-factor challenge screen (WP6; DESIGN.md §7.1). Fortify handles the POST. */
class TwoFactorChallengeScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_confirmed_two_factor_login_is_sent_to_the_challenge(): void
    {
        $user = $this->twoFactorAccount();

        $this->post('/login', ['email' => $user->email, 'password' => 'password-for-tests'])
            ->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_the_challenge_renders_the_code_field(): void
    {
        $this->startChallenge($this->twoFactorAccount());

        $this->get('/two-factor-challenge')
            ->assertOk()
            ->assertSee('Two-step verification')
            ->assertSee('Authentication code')
            ->assertSee('name="code"', false)
            ->assertSee('inputmode="numeric"', false)
            ->assertSee('autocomplete="one-time-code"', false)
            ->assertSee('maxlength="6"', false)
            ->assertSee('Use a recovery code instead')
            ->assertSee('name="recovery_code"', false)
            ->assertSee('Back to log in')
            ->assertSee('action="'.route('two-factor.login.store').'"', false);
    }

    public function test_a_valid_code_logs_in_and_lands_on_home(): void
    {
        $user = $this->twoFactorAccount();
        $this->startChallenge($user);

        $this->post('/two-factor-challenge', ['code' => $this->currentCode($user)])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->get('/')->assertOk()->assertSee($user->email);
    }

    public function test_a_wrong_code_shows_one_alert_and_stays_a_guest(): void
    {
        $this->startChallenge($this->twoFactorAccount());

        $this->from('/two-factor-challenge')->post('/two-factor-challenge', ['code' => '000000'])
            ->assertRedirect('/two-factor-challenge');

        $this->get('/two-factor-challenge')
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('We couldn\'t verify that code')
            ->assertSee('The provided two factor authentication code was invalid.')
            ->assertDontSee('aria-invalid="true"', false);
        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = $this->twoFactorAccount();
        $this->startChallenge($user);

        $this->post('/two-factor-challenge', ['recovery_code' => 'code-one-aaaa'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');
        $this->assertGuest();

        $this->startChallenge($user);
        $this->from('/two-factor-challenge')->post('/two-factor-challenge', ['recovery_code' => 'code-one-aaaa'])
            ->assertRedirect('/two-factor-challenge');

        $this->get('/two-factor-challenge')
            ->assertSee('The provided two factor recovery code was invalid.');
        $this->assertGuest();
    }

    public function test_the_challenge_without_a_pending_login_returns_to_login(): void
    {
        $this->get('/two-factor-challenge')->assertRedirect(route('login'));
    }

    private function twoFactorAccount(): User
    {
        return User::factory()->student()->twoFactor()->create()->fresh();
    }

    private function startChallenge(User $user): void
    {
        $this->post('/login', ['email' => $user->email, 'password' => 'password-for-tests'])
            ->assertRedirect(route('two-factor.login'));
    }

    private function currentCode(User $user): string
    {
        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        return app(Google2FA::class)->getCurrentOtp($secret);
    }
}
