<?php

namespace Tests\Feature\Mcp;

use App\Identity\Accounts;
use App\Identity\Fortify\ResetUserPassword;
use App\Identity\PrincipalFactory;
use App\Identity\TwoFactorReset;
use App\Mcp\Connections;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Access\Role;
use App\Platform\Errors\Forbidden;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Tests\Concerns\ConnectsAiApps;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Who a token acts as, and when it stops (docs/specs/mcp-connections-plan.md §3.2, M6.1). */
class PrincipalTest extends TestCase
{
    use ConnectsAiApps, CreatesAccounts, RefreshesDatabase;

    public function test_a_token_acts_as_the_student_only_even_for_an_admin_who_studies(): void
    {
        $abel = $this->admin(student: true);
        $clientId = $this->registerApp();
        Passport::actingAs($abel, ['mcp:use'], 'api', Client::query()->findOrFail($clientId));

        $principal = app(PrincipalFactory::class)->forToken(request());

        $this->assertSame('mcp', $principal->channel);
        $this->assertSame([Role::Student], $principal->roles);
        $this->assertNull($principal->passwordConfirmedAt);
        $this->assertNotNull($principal->learnerId);
        try {
            Guard::admin($principal);
            $this->fail('A token passed the admin guard.');
        } catch (Forbidden $e) {
            $this->assertSame('admin_role_required', $e->errorCode);
        }
    }

    public function test_an_account_that_is_only_an_admin_cannot_connect(): void
    {
        $admin = $this->admin(student: false);
        $clientId = $this->registerApp();
        Passport::actingAs($admin, ['mcp:use'], 'api', Client::query()->findOrFail($clientId));

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'student_role_required');
    }

    public function test_a_suspended_student_is_refused_without_a_sign_in_challenge(): void
    {
        $ada = $this->student();
        $tokens = $this->connectApp($ada);
        DB::table('users')->where('id', $ada->id)->update(['status' => 'suspended']);

        $response = $this->callTool($tokens['access_token'], 'me')->assertStatus(403)->assertJsonPath('error.code', 'access_revoked');
        $this->assertFalse($response->headers->has('WWW-Authenticate'));
    }

    public function test_a_deleted_student_is_refused_without_a_sign_in_challenge(): void
    {
        $ada = $this->student();
        $tokens = $this->connectApp($ada);
        DB::table('users')->where('id', $ada->id)->update(['status' => 'deleted']);

        $response = $this->callTool($tokens['access_token'], 'me')->assertStatus(403)->assertJsonPath('error.code', 'account_deleted');
        $this->assertFalse($response->headers->has('WWW-Authenticate'));
    }

    public function test_after_disconnect_the_next_call_must_sign_in_again(): void
    {
        $ada = $this->student();
        $tokens = $this->connectApp($ada);
        $this->callTool($tokens['access_token'], 'me')->assertOk();

        $connections = app(Connections::class);
        $id = $connections->list($this->principal($ada))[0]->id;
        $connections->disconnect($this->principal($ada), $id);

        $this->callTool($tokens['access_token'], 'me')->assertStatus(401)->assertHeader('WWW-Authenticate');
        $this->assertNotNull(DB::table('mcp_connections')->value('revoked_at'));
        $this->assertSame(0, DB::table('oauth_refresh_tokens')->where('revoked', false)->count());
    }

    public function test_suspension_a_2fa_reset_and_a_new_password_cut_every_app(): void
    {
        foreach (['suspend', 'two_factor', 'password'] as $event) {
            $ada = $this->student();
            $tokens = $this->connectApp($ada);
            match ($event) {
                'suspend' => app(Accounts::class)->suspend($this->principal($this->admin(student: false)), $ada->id),
                'two_factor' => app(TwoFactorReset::class)->reset(Principal::system(), $ada->id),
                'password' => app(ResetUserPassword::class)->reset($ada->fresh(), self::passwordChange()),
            };

            $live = DB::table('mcp_connections')->where('learner_id', DB::table('learners')->where('user_id', $ada->id)->value('id'))->whereNull('revoked_at')->count();
            $this->assertSame(0, $live, "{$event} left a connection live");
            $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $ada->id)->where('revoked', false)->count(), "{$event} left a token live");
        }
    }

    public function test_a_token_without_the_mcp_scope_is_refused(): void
    {
        $ada = $this->student();
        $clientId = $this->registerApp();
        Passport::actingAs($ada, [], 'api', Client::query()->findOrFail($clientId));

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream'])
            ->assertStatus(403);
    }

    /** @return array<string, string> */
    private static function passwordChange(): array
    {
        return ['password' => 'a-new-long-password-1!', 'password_confirmation' => 'a-new-long-password-1!'];
    }
}
