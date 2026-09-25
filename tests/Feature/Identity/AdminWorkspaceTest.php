<?php

namespace Tests\Feature\Identity;

use App\Audit\AuditAction;
use App\Http\AdminRoutes;
use App\Identity\PrincipalFactory;
use App\Models\User;
use App\Platform\Access\Workspace;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * ADR 0003 §10.3–10.4: the /admin route group and the workspace switch.
 * Developer tests; the independent T1, T7 and T9 tests belong to V2.
 */
class AdminWorkspaceTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A stand-in admin screen, registered in the real admin group.
        Route::middleware('web')->group(fn () => AdminRoutes::group(function () {
            Route::get('_test/screen', fn () => ['ok' => true])->name('test.screen');
        }));
    }

    public function test_a_student_gets_403_on_any_admin_url_and_the_attempt_is_audited(): void
    {
        $student = $this->student();

        $this->actingAs($student)->getJson('/admin/_test/screen')->assertForbidden()->assertJsonPath('error.code', 'admin_role_required');
        $this->actingAs($student)->getJson('/admin/guessed/url')->assertForbidden();
        $this->actingAs($student)->get('/admin')->assertForbidden();

        $this->assertCount(3, $this->auditRows(AuditAction::ADMIN_ACCESS_DENIED));
    }

    public function test_guests_are_sent_away(): void
    {
        $this->getJson('/admin/_test/screen')->assertUnauthorized();
        $this->get('/admin/_test/screen')->assertRedirect(route('login'));
    }

    public function test_an_admin_without_2fa_is_sent_to_enrol(): void
    {
        $admin = User::factory()->admin()->student()->create();

        $this->actingAs($admin)->withSession($this->confirmedSession())
            ->getJson('/admin/_test/screen')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');
    }

    public function test_entering_the_admin_workspace_by_url_needs_a_recent_password_confirmation(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->getJson('/admin/_test/screen')
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'password_confirmation_required');

        $this->actingAs($admin)->withSession($this->confirmedSession())
            ->getJson('/admin/_test/screen')
            ->assertOk()
            ->assertSessionHas(PrincipalFactory::WORKSPACE_KEY, 'admin');

        $this->assertCount(1, $this->auditRows(AuditAction::WORKSPACE_ADMIN_ENTERED));
    }

    public function test_inside_the_admin_workspace_later_requests_pass_without_reconfirming(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->withSession([PrincipalFactory::WORKSPACE_KEY => 'admin'])
            ->getJson('/admin/_test/screen')
            ->assertOk();
    }

    public function test_unknown_admin_urls_are_404_for_admins(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->withSession($this->confirmedSession(Workspace::Admin))
            ->getJson('/admin/no/such/screen')
            ->assertNotFound();
    }

    public function test_switching_workspace(): void
    {
        $student = $this->student();
        $admin = $this->admin();

        $this->actingAs($student)->postJson('/workspace/admin')->assertForbidden();

        $this->actingAs($admin)->postJson('/workspace/admin')->assertStatus(423);
        $this->actingAs($admin)->withSession($this->confirmedSession())->postJson('/workspace/admin')
            ->assertNoContent()
            ->assertSessionHas(PrincipalFactory::WORKSPACE_KEY, 'admin');
        $this->actingAs($admin)->postJson('/workspace/student')
            ->assertNoContent()
            ->assertSessionHas(PrincipalFactory::WORKSPACE_KEY, 'student');

        $adminOnly = $this->admin(student: false);
        $this->actingAs($adminOnly)->postJson('/workspace/student')->assertForbidden()->assertJsonPath('error.code', 'student_role_required');
    }

    public function test_suspended_and_deleted_accounts_are_logged_out_on_their_next_request(): void
    {
        $suspended = User::factory()->student()->suspended()->create();
        $this->actingAs($suspended)->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('error.code', 'access_revoked');
        $this->assertGuest();

        $deleted = User::factory()->student()->deleted()->create();
        $this->actingAs($deleted)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'account_deleted');
        $this->assertGuest();
    }
}
