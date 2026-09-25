<?php

namespace App\Identity;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Models\User;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Errors\Conflict;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invite-only accounts (ADR 0003 D4). An invitation never carries a role:
 * accepting one always creates a student account, whatever the request says.
 */
final class Invitations
{
    public function __construct(
        private AccountFactory $factory,
        private PrincipalFactory $principals,
        private AuditLog $audit,
    ) {}

    public function invite(Principal $by, string $email): IssuedInvitation
    {
        Guard::protectedAdmin($by);

        $email = AccountFactory::normaliseEmail($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new Unprocessable('validation_failed', 'The email address is invalid.', ['fields' => ['email' => ['The email address is invalid.']]]);
        }
        if (User::query()->where('email', $email)->exists()) {
            throw new Conflict('email_taken', 'An account with this email address already exists.');
        }

        $id = Ids::new();
        $token = Str::random(48);
        $expiresAt = now()->addHours((int) config('vistud.invitations.ttl_hours', 72))->toDateTimeImmutable();

        DB::transaction(function () use ($by, $id, $email, $token, $expiresAt) {
            DB::table('invitations')->insert([
                'id' => $id,
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $by->userId,
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record($by, AuditAction::INVITATION_CREATED, 'invitation', $id, role: 'admin');
        });

        return new IssuedInvitation($id, $token, $expiresAt);
    }

    public function revoke(Principal $by, string $invitationId): void
    {
        Guard::protectedAdmin($by);

        DB::transaction(function () use ($by, $invitationId) {
            $invitation = DB::table('invitations')->where('id', $invitationId)->lockForUpdate()->first() ?? throw new NotFound;
            if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
                return;
            }
            DB::table('invitations')->where('id', $invitationId)->update(['revoked_at' => now(), 'updated_at' => now()]);
            $this->audit->record($by, AuditAction::INVITATION_REVOKED, 'invitation', $invitationId, role: 'admin');
        });
    }

    /**
     * Anyone holding a valid token. Takes no role: the new account is always
     * a student (ADR 0003 T5). Every failure gets the same answer, so a
     * token's state can't be probed.
     */
    public function accept(string $token, string $name, string $password, ?string $timezone = null): User
    {
        return DB::transaction(function () use ($token, $name, $password, $timezone) {
            $invitation = DB::table('invitations')
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null
                || $invitation->accepted_at !== null
                || $invitation->revoked_at !== null
                || now()->greaterThanOrEqualTo($invitation->expires_at)
                || User::query()->where('email', $invitation->email)->exists()) {
                throw new Unprocessable('invitation_invalid', 'This invitation is not valid. Ask for a new one.');
            }

            $user = $this->factory->create($name, $invitation->email, $password, student: true, timezone: $timezone, grantedBy: $invitation->invited_by);

            DB::table('invitations')->where('id', $invitation->id)->update([
                'accepted_at' => now(),
                'accepted_user_id' => $user->id,
                'updated_at' => now(),
            ]);
            $this->audit->record($this->principals->forUser($user, 'web'), AuditAction::INVITATION_ACCEPTED, 'invitation', $invitation->id);

            return $user;
        });
    }
}
