<?php

namespace Tests\Feature\Identity;

use App\Audit\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** ADR 0003 §10.3: first-admin setup and 2FA recovery from the server's shell, audited as system actions. */
class ConsoleCommandsTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_first_admin_setup(): void
    {
        $this->artisan('vistud:account:create', ['email' => 'owner@example.test', '--name' => 'Owner', '--timezone' => 'Europe/London'])
            ->expectsQuestion('Password (at least 12 characters)', 'a-long-enough-password')
            ->expectsQuestion('Repeat the password', 'a-long-enough-password')
            ->assertSuccessful();

        $this->artisan('vistud:admin:grant', ['email' => 'OWNER@example.test'])->assertSuccessful();

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame(['admin', 'student'], DB::table('user_roles')->where('user_id', $owner->id)->orderBy('role')->pluck('role')->all());
        $this->assertNull($owner->two_factor_confirmed_at, 'The new admin must still enrol in 2FA.');

        foreach ([AuditAction::ACCOUNT_CREATED, AuditAction::ROLE_GRANTED] as $action) {
            [$audit] = $this->auditRows($action);
            $this->assertSame(['system', 'system'], [$audit->actor_type, $audit->actor_role]);
            $this->assertNotNull($audit->request_id);
        }
    }

    public function test_account_creation_refuses_mismatched_or_short_passwords(): void
    {
        $this->artisan('vistud:account:create', ['email' => 'a@example.test', '--name' => 'A'])
            ->expectsQuestion('Password (at least 12 characters)', 'a-long-enough-password')
            ->expectsQuestion('Repeat the password', 'something-else-entirely')
            ->assertFailed();

        $this->artisan('vistud:account:create', ['email' => 'a@example.test', '--name' => 'A'])
            ->expectsQuestion('Password (at least 12 characters)', 'short')
            ->expectsQuestion('Repeat the password', 'short')
            ->assertFailed();

        $this->assertFalse(User::query()->where('email', 'a@example.test')->exists());
    }

    public function test_an_account_can_be_created_without_the_student_role(): void
    {
        $this->artisan('vistud:account:create', ['email' => 'ops@example.test', '--name' => 'Ops', '--no-student' => true])
            ->expectsQuestion('Password (at least 12 characters)', 'a-long-enough-password')
            ->expectsQuestion('Repeat the password', 'a-long-enough-password')
            ->assertSuccessful();

        $ops = User::query()->where('email', 'ops@example.test')->firstOrFail();
        $this->assertFalse(DB::table('learners')->where('user_id', $ops->id)->exists());
    }

    public function test_resetting_2fa_from_the_console(): void
    {
        $admin = $this->admin(attributes: ['email' => 'locked-out@example.test']);

        $this->artisan('vistud:admin:reset-2fa', ['email' => 'locked-out@example.test'])->assertSuccessful();

        $this->assertNull($admin->fresh()->two_factor_confirmed_at);
        [$audit] = $this->auditRows(AuditAction::TWO_FACTOR_RESET);
        $this->assertSame('system', $audit->actor_role);
    }

    public function test_unknown_emails_fail_cleanly(): void
    {
        $this->artisan('vistud:admin:grant', ['email' => 'nobody@example.test'])->assertFailed();
        $this->artisan('vistud:admin:reset-2fa', ['email' => 'nobody@example.test'])->assertFailed();
    }
}
