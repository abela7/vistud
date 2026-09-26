<?php

namespace Tests\Feature\Web;

use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The password confirmation screen (WP6). Fortify handles the POST. */
class ConfirmPasswordScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/user/confirm-password')->assertRedirect(route('login'));
    }

    public function test_a_signed_in_user_sees_the_form(): void
    {
        $this->actingAs($this->student())
            ->get('/user/confirm-password')
            ->assertOk()
            ->assertSee('Confirm your password')
            ->assertSee('For your security, enter your password to continue.')
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('action="'.route('password.confirm.store').'"', false)
            ->assertSee('href="'.route('home').'"', false);
    }

    public function test_a_wrong_password_is_shown_on_the_field_and_does_not_confirm_the_session(): void
    {
        $user = $this->student();

        $this->actingAs($user)
            ->from('/user/confirm-password')
            ->post('/user/confirm-password', ['password' => 'wrong-password'])
            ->assertRedirect('/user/confirm-password')
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('auth.password_confirmed_at');

        $this->actingAs($user)
            ->followingRedirects()
            ->from('/user/confirm-password')
            ->post('/user/confirm-password', ['password' => 'still-wrong'])
            ->assertOk()
            ->assertSee('id="field-password-error"', false)
            ->assertSee('The provided password was incorrect.')
            ->assertDontSee('role="alert"', false);
    }

    public function test_the_correct_password_confirms_the_session_and_returns_to_the_intended_url(): void
    {
        $this->actingAs($this->student())
            ->withSession(['url.intended' => '/after-confirm'])
            ->post('/user/confirm-password', ['password' => 'password-for-tests'])
            ->assertRedirect('/after-confirm')
            ->assertSessionHas('auth.password_confirmed_at');
    }
}
