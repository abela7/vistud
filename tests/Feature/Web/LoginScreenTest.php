<?php

namespace Tests\Feature\Web;

use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The login screen (WP6; DESIGN.md §7.1). Fortify handles the POST. */
class LoginScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_see_the_login_form_with_the_default_theme_pair(): void
    {
        $this->get('/')->assertRedirect(route('login'));

        $this->get('/login')
            ->assertOk()
            ->assertSee('data-theme-light="vistud-light"', false)
            ->assertSee('data-theme-dark="vistud-dark"', false)
            ->assertSee('action="'.route('login.store').'"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('invitation-only');
    }

    public function test_a_refused_login_shows_one_message_for_the_form_not_the_field(): void
    {
        $this->student(['email' => 'ada@example.test']);

        $this->from('/login')->post('/login', ['email' => 'ada@example.test', 'password' => 'wrong-password'])
            ->assertRedirect('/login');

        $this->get('/login')
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee(__('auth.failed'))
            ->assertDontSee('aria-invalid="true"', false)
            ->assertSee('value="ada@example.test"', false);
        $this->assertGuest();
    }

    public function test_missing_fields_are_marked_on_the_fields(): void
    {
        $this->from('/login')->post('/login', ['email' => '', 'password' => '']);

        $this->get('/login')
            ->assertSee('id="field-email-error"', false)
            ->assertSee('id="field-password-error"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertDontSee('role="alert"', false);
    }

    public function test_a_valid_login_lands_on_home(): void
    {
        $this->student(['email' => 'ada@example.test']);

        $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password-for-tests'])->assertRedirect('/');
        $this->get('/')->assertOk()->assertSee('ada@example.test');
        $this->get('/login')->assertRedirect('/');
    }

    public function test_logging_out_goes_straight_to_the_login_screen(): void
    {
        $this->actingAs($this->student());

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
