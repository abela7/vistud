<?php

namespace Tests\Feature\Identity;

use App\Audit\AuditAction;
use App\Identity\Invitations;
use App\Models\User;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\Forbidden;
use App\Platform\Errors\PasswordConfirmationRequired;
use App\Platform\Errors\Unprocessable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** ADR 0003 D4 (invite-only) and T5 (a role field changes nothing). */
class InvitationsTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    private Invitations $invitations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->invitations = app(Invitations::class);
    }

    public function test_inviting_is_a_protected_admin_action_and_stores_only_a_hash(): void
    {
        $this->assertThrows(fn () => $this->invitations->invite($this->principal($this->student()), 'x@example.test'), Forbidden::class);
        $this->assertThrows(fn () => $this->invitations->invite($this->principal($this->admin(), confirmed: false), 'x@example.test'), PasswordConfirmationRequired::class);

        $issued = $this->invitations->invite($this->principal($this->admin()), 'New@Example.test');

        $row = DB::table('invitations')->where('id', $issued->id)->first();
        $this->assertSame('new@example.test', $row->email);
        $this->assertSame(hash('sha256', $issued->token), $row->token_hash);
        $this->assertStringNotContainsString($issued->token, json_encode($row));
        [$audit] = $this->auditRows(AuditAction::INVITATION_CREATED);
        $this->assertNull($audit->metadata);
    }

    public function test_an_existing_email_cannot_be_invited(): void
    {
        $this->student(['email' => 'taken@example.test']);

        $this->assertThrows(fn () => $this->invitations->invite($this->principal($this->admin()), 'taken@example.test'), Conflict::class);
    }

    public function test_accepting_creates_a_student_once(): void
    {
        $issued = $this->invitations->invite($this->principal($this->admin()), 'new@example.test');

        $user = $this->invitations->accept($issued->token, 'New Student', 'a-long-enough-password');

        $this->assertSame(['student'], DB::table('user_roles')->where('user_id', $user->id)->pluck('role')->all());
        $this->assertTrue(DB::table('learners')->where('user_id', $user->id)->exists());
        $this->assertCount(1, $this->auditRows(AuditAction::INVITATION_ACCEPTED));

        $this->assertThrows(fn () => $this->invitations->accept($issued->token, 'Again', 'a-long-enough-password'), Unprocessable::class);
    }

    public function test_expired_revoked_and_unknown_tokens_get_the_same_answer(): void
    {
        $admin = $this->admin();
        $expired = $this->invitations->invite($this->principal($admin), 'expired@example.test');
        $revoked = $this->invitations->invite($this->principal($admin), 'revoked@example.test');
        $this->invitations->revoke($this->principal($admin), $revoked->id);
        $this->travel(73)->hours();

        foreach ([$expired->token, $revoked->token, 'no-such-token'] as $token) {
            try {
                $this->invitations->accept($token, 'Someone', 'a-long-enough-password');
                $this->fail('The invitation was accepted.');
            } catch (Unprocessable $e) {
                $this->assertSame(['invitation_invalid', []], [$e->errorCode, $e->details]);
            }
        }
    }

    public function test_a_role_field_in_the_acceptance_request_changes_nothing(): void
    {
        $issued = $this->invitations->invite($this->principal($this->admin()), 'sneaky@example.test');

        $this->postJson('/invitations/accept', [
            'token' => $issued->token,
            'name' => 'Sneaky',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
            'role' => 'admin',
            'roles' => ['admin'],
            'status' => 'active',
        ])->assertCreated();

        $user = User::query()->where('email', 'sneaky@example.test')->firstOrFail();
        $this->assertSame(['student'], DB::table('user_roles')->where('user_id', $user->id)->pluck('role')->all());
        $this->assertAuthenticatedAs($user);
    }
}
