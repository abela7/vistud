<?php

namespace Tests\Feature\Identity;

use App\Audit\AuditAction;
use App\Identity\Roles;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\Forbidden;
use App\Platform\Errors\PasswordConfirmationRequired;
use App\Platform\Errors\TwoFactorRequired;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** ADR 0003 §10.2–10.3: granting and revoking admin (T6, T10 at the service level). */
class RolesTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private Roles $roles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roles = app(Roles::class);
    }

    public function test_the_console_grants_the_first_admin_and_it_is_audited_as_system(): void
    {
        $student = $this->student();

        $this->roles->grantAdmin(Principal::system(), $student->id);

        $this->assertTrue(DB::table('user_roles')->where('user_id', $student->id)->where('role', 'admin')->exists());
        [$audit] = $this->auditRows(AuditAction::ROLE_GRANTED);
        $this->assertSame(['system', 'system', '{"role": "admin"}'], [$audit->actor_type, $audit->actor_role, $audit->metadata]);
    }

    public function test_granting_needs_an_admin_with_2fa_and_a_recent_confirmation(): void
    {
        $target = $this->student();

        $this->assertThrows(fn () => $this->roles->grantAdmin($this->principal($this->student()), $target->id), Forbidden::class);
        $this->assertThrows(fn () => $this->roles->grantAdmin($this->principal(User::factory()->admin()->create()), $target->id), TwoFactorRequired::class);
        $this->assertThrows(fn () => $this->roles->grantAdmin($this->principal($this->admin(), confirmed: false), $target->id), PasswordConfirmationRequired::class);
        $this->assertFalse(DB::table('user_roles')->where('user_id', $target->id)->where('role', 'admin')->exists());

        $this->roles->grantAdmin($this->principal($this->admin()), $target->id);
        $this->assertTrue(DB::table('user_roles')->where('user_id', $target->id)->where('role', 'admin')->exists());
    }

    public function test_a_student_cannot_grant_themselves_admin(): void
    {
        $student = $this->student();

        $this->assertThrows(fn () => $this->roles->grantAdmin($this->principal($student), $student->id), Forbidden::class);
        $this->assertSame([], $this->auditRows(AuditAction::ROLE_GRANTED));
    }

    public function test_granting_twice_changes_nothing_the_second_time(): void
    {
        $student = $this->student();
        $this->roles->grantAdmin(Principal::system(), $student->id);
        $this->roles->grantAdmin(Principal::system(), $student->id);

        $this->assertCount(1, $this->auditRows(AuditAction::ROLE_GRANTED));
    }

    public function test_revoking_is_audited_and_refused_for_the_last_active_admin(): void
    {
        $first = $this->admin();
        $second = $this->admin();

        $this->roles->revokeAdmin($this->principal($first), $second->id);
        $this->assertFalse(DB::table('user_roles')->where('user_id', $second->id)->where('role', 'admin')->exists());
        $this->assertCount(1, $this->auditRows(AuditAction::ROLE_REVOKED));

        $this->assertThrows(fn () => $this->roles->revokeAdmin($this->principal($first), $first->id), Conflict::class);
    }

    public function test_the_console_cannot_revoke(): void
    {
        $first = $this->admin();
        $this->admin();

        $this->assertThrows(fn () => $this->roles->revokeAdmin(Principal::system(), $first->id), Forbidden::class);
    }
}
