<?php

namespace Tests\Feature\Identity;

use App\Engine\Engine;
use App\Engine\Fake;
use App\Engine\Settings;
use App\Identity\Invitations;
use App\Identity\JoiningPolicy;
use App\Identity\PrincipalFactory;
use App\Models\User;
use App\Notifications\VerifyStudentEmail;
use App\Platform\Errors\Forbidden;
use App\Platform\Errors\Unprocessable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\JoiningPolicyFixture;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

class ClassJoiningTest extends TestCase
{
    use CreatesAccounts, JoiningPolicyFixture, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->joiningPolicy();
        Notification::fake();
        $this->app->instance(Engine::class, new Fake);
    }

    private function form(string $token, string $email = 'new@example.test'): array
    {
        return ['token' => $token, 'email' => $email, 'name' => 'Synthetic Student', 'password' => bin2hex(random_bytes(16)),
            'timezone' => 'Europe/London', 'privacy' => '1', 'privacy_version' => app(JoiningPolicy::class)->version()];
    }

    public function test_class_link_requires_verification_on_web_livewire_api_mcp_and_jobs(): void
    {
        $invite = app(Invitations::class)->classLink($this->principal($this->admin()), 2, now()->addDay()->toDateTimeString());
        $form = $this->form($invite->token);
        $this->post('/class-links/accept', $form + ['password_confirmation' => $form['password']])->assertRedirect(route('verification.notice'));
        $user = User::where('email', $form['email'])->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertSame($form['privacy_version'], $user->privacy_version);
        $this->assertNotNull($user->privacy_accepted_at);
        Notification::assertSentTo($user, VerifyStudentEmail::class);
        $this->get('/')->assertRedirect(route('verification.notice'));
        $this->postJson(Livewire::getUpdateUri(), [])->assertForbidden()->assertJsonPath('error.code', 'email_unverified');
        $this->getJson('/api/v1/me')->assertForbidden();
        foreach (['web', 'mcp', 'job'] as $channel) {
            try {
                app(PrincipalFactory::class)->forUser($user, $channel);
                $this->fail('Unverified principal admitted');
            } catch (Forbidden $e) {
                $this->assertSame('email_unverified', $e->errorCode);
            }
        }
        $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'token' => sha1($user->email)]))->assertRedirect('/join/key?verified=1');
        $this->get('/join/key')->assertOk()->assertSee('Add your AI key')->assertDontSee('Course');
        $key = bin2hex(random_bytes(32));
        $this->post('/join/key', ['key' => $key])->assertRedirect('/');
        $this->assertSame($key, app(Settings::class)->key($this->principal($user)));
    }

    public function test_capacity_expiry_disable_and_failed_account_creation_never_oversubscribe(): void
    {
        $by = $this->principal($this->admin());
        $service = app(Invitations::class);
        $link = $service->classLink($by, 1, now()->addDay()->toDateTimeString());
        $join = fn ($email, $password) => $service->joinClass($link->token, $email, 'Student', $password, null, app(JoiningPolicy::class)->version());
        $this->assertThrows(fn () => $join('one@example.test', 'x'), ValidationException::class);
        $this->assertSame(0, DB::table('invitations')->where('id', $link->id)->value('uses'));
        $join('one@example.test', bin2hex(random_bytes(16)));
        $this->assertThrows(fn () => $join('two@example.test', bin2hex(random_bytes(16))), Unprocessable::class);
        $this->assertSame(1, DB::table('invitations')->where('id', $link->id)->value('uses'));
        $off = $service->classLink($by, 3, now()->addDay()->toDateTimeString());
        $service->revoke($by, $off->id);
        $this->assertThrows(fn () => $service->joinClass($off->token, 'off@example.test', 'Student', bin2hex(random_bytes(16)), null, app(JoiningPolicy::class)->version()), Unprocessable::class);
        $expires = $service->classLink($by, 3, now()->addDay()->toDateTimeString());
        $this->travel(2)->days();
        $this->assertThrows(fn () => $service->joinClass($expires->token, 'expired@example.test', 'Student', bin2hex(random_bytes(16)), null, app(JoiningPolicy::class)->version()), Unprocessable::class);
        $this->assertThrows(fn () => $service->classLink($this->principal($this->student()), 10, now()->addDay()->toDateTimeString()), Forbidden::class);
    }

    public function test_policy_acceptance_is_required_and_bound_to_current_text_for_both_flows(): void
    {
        $service = app(Invitations::class);
        $email = $service->invite($this->principal($this->admin()), 'email@example.test');
        $form = $this->form($email->token);
        $form['password_confirmation'] = $form['password'];
        unset($form['privacy']);
        $this->postJson('/invitations/accept', $form)->assertUnprocessable();
        $form['privacy'] = '1';
        file_put_contents(config('vistud.privacy.path'), '# A changed synthetic policy');
        $this->postJson('/invitations/accept', $form)->assertUnprocessable()->assertJsonPath('error.code', 'privacy_required');
        config(['vistud.privacy.path' => config('vistud.files.temp_root').'/missing.md']);
        $this->get('/invitation')->assertSee('Joining is not available yet');
        $this->postJson('/invitations/accept', $form)->assertUnprocessable();
        $this->assertNull(User::where('email', 'email@example.test')->first());
    }

    public function test_invites_stay_single_use_and_the_shared_ip_limiter_counts_both_joining_routes(): void
    {
        $invite = app(Invitations::class)->invite($this->principal($this->admin()), 'email@example.test');
        $form = $this->form($invite->token);
        $form['password_confirmation'] = $form['password'];
        $this->postJson('/invitations/accept', $form)->assertCreated();
        $user = User::where('email', 'email@example.test')->firstOrFail();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNull(DB::table('user_roles')->where('user_id', $user->id)->where('role', 'admin')->first());
        auth()->logout();
        $this->postJson('/invitations/accept', $form)->assertUnprocessable();
        for ($i = 0; $i < 8; $i++) {
            $this->postJson($i % 2 ? '/invitations/accept' : '/class-links/accept', [])->assertUnprocessable();
        }
        $this->postJson('/class-links/accept', [])->assertStatus(429);
    }
}
