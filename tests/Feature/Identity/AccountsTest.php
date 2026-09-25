<?php

namespace Tests\Feature\Identity;

use App\Audit\AuditAction;
use App\Identity\Accounts;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\Forbidden;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\PasswordConfirmationRequired;
use App\Platform\Errors\TwoFactorRequired;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** ADR 0003 §10.2–10.3: account administration through the service, whatever the adapter. */
class AccountsTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private Accounts $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->accounts = app(Accounts::class);
    }

    public function test_the_console_creates_a_student_with_a_learner_and_never_an_admin(): void
    {
        $user = $this->accounts->create(Principal::system(), 'Ada', ' Ada@Example.test ', 'a-long-enough-password', timezone: 'Europe/London');

        $this->assertSame('ada@example.test', $user->email);
        $this->assertSame(['student'], DB::table('user_roles')->where('user_id', $user->id)->pluck('role')->all());
        $this->assertSame('Europe/London', DB::table('learners')->where('user_id', $user->id)->value('timezone'));

        [$audit] = $this->auditRows(AuditAction::ACCOUNT_CREATED);
        $this->assertSame(['system', null, 'system', $user->id], [$audit->actor_type, $audit->actor_user_id, $audit->actor_role, $audit->target_id]);
        $this->assertStringNotContainsString('ada@', (string) $audit->metadata);
    }

    public function test_an_account_without_the_student_role_has_no_learner(): void
    {
        $user = $this->accounts->create(Principal::system(), 'Ops', 'ops@example.test', 'a-long-enough-password', student: false);

        $this->assertSame([], DB::table('user_roles')->where('user_id', $user->id)->pluck('role')->all());
        $this->assertFalse(DB::table('learners')->where('user_id', $user->id)->exists());
    }

    public function test_creating_an_account_is_a_protected_admin_action(): void
    {
        $admin = $this->admin();
        $this->accounts->create($this->principal($admin), 'New', 'new@example.test', 'a-long-enough-password');

        $this->assertThrows(fn () => $this->accounts->create($this->principal($admin, confirmed: false), 'X', 'x@example.test', 'a-long-enough-password'), PasswordConfirmationRequired::class);
        $this->assertThrows(fn () => $this->accounts->create($this->principal($this->student()), 'Y', 'y@example.test', 'a-long-enough-password'), Forbidden::class);
        $noTwoFactor = User::factory()->admin()->create();
        $this->assertThrows(fn () => $this->accounts->create($this->principal($noTwoFactor), 'Z', 'z@example.test', 'a-long-enough-password'), TwoFactorRequired::class);
    }

    public function test_emails_are_unique_and_passwords_have_a_minimum_length(): void
    {
        $this->student(['email' => 'taken@example.test']);

        $this->assertThrows(fn () => $this->accounts->create(Principal::system(), 'A', 'TAKEN@example.test', 'a-long-enough-password'), Conflict::class);
        $this->assertThrows(fn () => $this->accounts->create(Principal::system(), 'B', 'b@example.test', 'short'), ValidationException::class);
    }

    public function test_suspending_ends_sessions_and_is_audited(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $student->id, 'payload' => '', 'last_activity' => time()]);

        $this->accounts->suspend($this->principal($admin), $student->id);

        $this->assertSame('suspended', $student->fresh()->status);
        $this->assertFalse(DB::table('sessions')->where('user_id', $student->id)->exists());
        [$audit] = $this->auditRows(AuditAction::ACCOUNT_SUSPENDED);
        $this->assertSame([$admin->id, 'admin'], [$audit->actor_user_id, $audit->actor_role]);

        $this->accounts->reactivate($this->principal($admin), $student->id);
        $this->assertSame('active', $student->fresh()->status);
    }

    public function test_suspending_needs_a_recent_password_confirmation(): void
    {
        $admin = $this->admin();
        $student = $this->student();

        $this->travelTo(now());
        $principal = $this->principal($admin);
        $this->travel(601)->seconds();

        $this->assertThrows(fn () => $this->accounts->suspend($principal, $student->id), PasswordConfirmationRequired::class);
        $this->assertSame('active', $student->fresh()->status);
    }

    public function test_the_last_active_admin_cannot_be_suspended_or_deleted(): void
    {
        $admin = $this->admin();

        $this->assertThrows(fn () => $this->accounts->suspend($this->principal($admin), $admin->id), Conflict::class);
        $this->assertThrows(fn () => $this->accounts->requestDeletion($this->principal($admin), $admin->id), Conflict::class);
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_one_of_two_admins_can_be_suspended_and_then_the_other_is_the_last(): void
    {
        $first = $this->admin();
        $second = $this->admin();

        $this->accounts->suspend($this->principal($first), $second->id);

        $this->assertThrows(fn () => $this->accounts->suspend($this->principal($first), $first->id), Conflict::class);
    }

    public function test_a_suspended_admin_does_not_count_as_active(): void
    {
        $admin = $this->admin();
        User::factory()->admin()->twoFactor()->suspended()->create();

        $this->assertThrows(fn () => $this->accounts->suspend($this->principal($admin), $admin->id), Conflict::class);
    }

    public function test_students_can_delete_their_own_account_with_a_recent_confirmation(): void
    {
        $student = $this->student();

        $this->assertThrows(fn () => $this->accounts->requestDeletion($this->principal($student, confirmed: false), $student->id), PasswordConfirmationRequired::class);

        $this->accounts->requestDeletion($this->principal($student), $student->id);

        $this->assertSame('deleted', $student->fresh()->status);
        [$audit] = $this->auditRows(AuditAction::ACCOUNT_DELETION_REQUESTED);
        $this->assertSame(['student', '{"self": true}'], [$audit->actor_role, $audit->metadata]);
    }

    public function test_students_cannot_delete_or_suspend_someone_else(): void
    {
        $student = $this->student();
        $other = $this->student();

        $this->assertThrows(fn () => $this->accounts->requestDeletion($this->principal($student), $other->id), Forbidden::class);
        $this->assertThrows(fn () => $this->accounts->suspend($this->principal($student), $other->id), Forbidden::class);
    }

    public function test_the_last_admin_cannot_delete_their_own_account(): void
    {
        $admin = $this->admin();

        $this->assertThrows(fn () => $this->accounts->requestDeletion($this->principal($admin), $admin->id), Conflict::class);
    }

    public function test_admins_see_account_details_and_nothing_else(): void
    {
        $admin = $this->admin();
        $student = $this->student();

        $details = $this->accounts->details($this->principal($admin, confirmed: false), $student->id)->toArray();

        $this->assertSame(
            ['id', 'name', 'email', 'status', 'roles', 'two_factor_confirmed', 'created_at', 'status_changed_at', 'last_active_at'],
            array_keys($details),
        );
        $this->assertSame(['student'], $details['roles']);
        $this->assertThrows(fn () => $this->accounts->details($this->principal($admin), 'missing-id'), NotFound::class);
        $this->assertThrows(fn () => $this->accounts->details($this->principal($student), $admin->id), Forbidden::class);
    }

    public function test_listing_accounts_pages_by_cursor(): void
    {
        $admin = $this->admin();
        $this->student();
        $this->student();

        $first = $this->accounts->list($this->principal($admin), limit: 2);
        $second = $this->accounts->list($this->principal($admin), $first['next_cursor'], limit: 2);

        $this->assertCount(2, $first['data']);
        $this->assertCount(1, $second['data']);
        $this->assertNull($second['next_cursor']);
    }
}
