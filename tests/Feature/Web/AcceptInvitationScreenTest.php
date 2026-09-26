<?php

namespace Tests\Feature\Web;

use App\Identity\Invitations;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** The invitation acceptance page (WP6; ADR 0003 D4 and T5, PM decision Q3). */
class AcceptInvitationScreenTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_page_shows_the_form_with_empty_token_and_time_zone_fields(): void
    {
        $this->get('/invitation')
            ->assertOk()
            ->assertSee('<title>Accept your invitation', false)
            ->assertSee('Welcome to ViStud')
            ->assertSee('data-invitation-form', false)
            ->assertSee('<input type="hidden" name="token" value="">', false)
            ->assertSee('action="'.route('invitations.accept.store').'"', false);
    }

    public function test_someone_already_logged_in_is_told_to_log_out_first(): void
    {
        $this->actingAs($this->student(['email' => 'ada@example.test']))
            ->get('/invitation')
            ->assertOk()
            ->assertSee("You're already logged in", false)
            ->assertSee('ada@example.test')
            ->assertDontSee('data-invitation-form', false);
    }

    public function test_accepting_logs_the_new_student_in_and_welcomes_them(): void
    {
        $token = $this->invite('new@example.test');

        $this->post('/invitations/accept', $this->form($token))
            ->assertRedirect('/')
            ->assertSessionHas('status', 'invitation-accepted');

        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Europe/London', DB::table('learners')->where('user_id', $user->id)->value('timezone'));

        $this->withSession(['status' => 'invitation-accepted'])->get('/')
            ->assertSee('Welcome, Ada')
            ->assertSee('From now on, log in with new@example.test');
    }

    public function test_an_invalid_link_gets_the_same_answer_and_keeps_nothing(): void
    {
        $this->from('/invitation')->post('/invitations/accept', $this->form('no-such-token'))
            ->assertRedirect(route('invitations.show'))
            ->assertSessionHasErrors('invitation')
            ->assertSessionMissing('_old_input');

        $this->followRedirects($this->from('/invitation')->post('/invitations/accept', $this->form('no-such-token')))
            ->assertSee("This link doesn't work", false)
            ->assertDontSee('data-invitation-form', false);
        $this->assertGuest();
    }

    public function test_a_field_mistake_keeps_the_name_but_never_the_token_or_password(): void
    {
        $token = $this->invite('new@example.test');

        $this->from('/invitation')->post('/invitations/accept', ['password' => 'short', 'password_confirmation' => 'short'] + $this->form($token))
            ->assertRedirect('/invitation')
            ->assertSessionHasErrors('password');

        $old = session('_old_input');
        $this->assertSame('Ada Lovelace', $old['name']);
        $this->assertArrayNotHasKey('token', $old);
        $this->assertArrayNotHasKey('password', $old);
        $this->assertGuest();
    }

    public function test_json_clients_still_get_the_error_envelope(): void
    {
        $this->postJson('/invitations/accept', $this->form('no-such-token'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invitation_invalid');
    }

    private function invite(string $email): string
    {
        return app(Invitations::class)->invite($this->principal($this->admin()), $email)->token;
    }

    /** @return array<string, string> */
    private function form(string $token): array
    {
        return [
            'token' => $token,
            'name' => 'Ada Lovelace',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
            'timezone' => 'Europe/London',
        ];
    }
}
