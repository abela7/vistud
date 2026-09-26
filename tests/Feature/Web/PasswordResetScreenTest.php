<?php

namespace Tests\Feature\Web;

use App\Http\Responses\NeutralPasswordResetLinkResponse;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Forgot-password and reset-password screens (WP6). Fortify handles the POSTs. */
class PasswordResetScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private const NEW_PASSWORD = 'replacement-password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_login_page_links_to_the_request_form(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('href="'.route('password.request').'"', false)
            ->assertSee('Forgot password?');
    }

    public function test_a_guest_can_open_the_request_form(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('Reset your password')
            ->assertSee('action="'.route('password.email').'"', false)
            ->assertSee('Back to log in');
    }

    public function test_an_existing_email_and_an_unknown_email_get_the_same_message(): void
    {
        $user = $this->student(['email' => 'ada@example.test']);
        $message = NeutralPasswordResetLinkResponse::MESSAGE;

        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.test']);

        $known->assertRedirect('/forgot-password')->assertSessionHas('status');
        $unknown->assertRedirect('/forgot-password')->assertSessionHas('status');
        $known->assertSessionMissing('errors');
        $unknown->assertSessionMissing('errors');

        foreach ([$user->email, 'nobody@example.test'] as $email) {
            $this->followingRedirects()
                ->from('/forgot-password')
                ->post('/forgot-password', ['email' => $email])
                ->assertOk()
                ->assertSee($message)
                ->assertDontSee("We can't find a user", false)
                ->assertDontSee('id="field-email-error"', false);
        }
    }

    public function test_json_clients_also_get_the_same_answer_for_known_and_unknown_emails(): void
    {
        $this->student(['email' => 'ada@example.test']);

        $known = $this->postJson('/forgot-password', ['email' => 'ada@example.test'])->assertOk()->json();
        $unknown = $this->postJson('/forgot-password', ['email' => 'nobody@example.test'])->assertOk()->json();

        $this->assertSame($known, $unknown);
    }

    public function test_a_blank_or_badly_formed_email_is_a_field_error(): void
    {
        $this->from('/forgot-password')->post('/forgot-password', ['email' => ''])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors('email');

        $this->followingRedirects()
            ->from('/forgot-password')
            ->post('/forgot-password', ['email' => ''])
            ->assertOk()
            ->assertSee('id="field-email-error"', false)
            ->assertDontSee(NeutralPasswordResetLinkResponse::MESSAGE);

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');
    }

    public function test_the_reset_mail_is_sent_only_when_the_account_exists(): void
    {
        Notification::fake();
        $user = $this->student(['email' => 'ada@example.test']);

        $this->post('/forgot-password', ['email' => $user->email]);
        $this->post('/forgot-password', ['email' => 'nobody@example.test']);

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_a_valid_token_sets_a_password_that_can_log_in(): void
    {
        $user = $this->student(['email' => 'ada@example.test']);
        $token = Password::broker()->createToken($user);

        $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))
            ->assertOk()
            ->assertSee('value="'.$user->email.'"', false)
            ->assertSee('New password')
            ->assertSee('Confirm new password')
            ->assertSee('name="token"', false);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/login');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));

        $this->post('/login', ['email' => $user->email, 'password' => self::NEW_PASSWORD])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_invalid_token_shows_an_error(): void
    {
        $user = $this->student(['email' => 'ada@example.test']);

        $this->from('/reset-password/not-a-token')->post('/reset-password', [
            'token' => 'not-a-token',
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/reset-password/not-a-token')->assertSessionHasErrors('email');

        $this->followingRedirects()
            ->from('/reset-password/not-a-token')
            ->post('/reset-password', [
                'token' => 'not-a-token',
                'email' => $user->email,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSee('id="field-email-error"', false);
    }
}
