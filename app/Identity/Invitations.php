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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
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
            DB::afterCommit(fn () => Notification::route('mail', $email)->notify(new InvitationMail($token)));
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
     * Admin with 2FA. Invitations not yet accepted or cancelled, newest
     * first, including expired ones, but not those whose email address has
     * since got an account.
     *
     * @return list<PendingInvitation>
     */
    public function pending(Principal $by, int $limit = 100): array
    {
        Guard::admin($by);

        return DB::table('invitations')
            ->leftJoin('users as inviters', 'inviters.id', '=', 'invitations.invited_by')
            ->where('invitations.kind', 'email')
            ->whereNull('invitations.accepted_at')
            ->whereNull('invitations.revoked_at')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('users')->whereColumn('users.email', 'invitations.email'))
            ->orderByDesc('invitations.created_at')
            ->orderByDesc('invitations.id')
            ->limit(max(1, min($limit, 200)))
            ->get(['invitations.id', 'invitations.email', 'inviters.name as inviter', 'invitations.created_at', 'invitations.expires_at'])
            ->map(fn (object $row) => new PendingInvitation(
                id: $row->id,
                email: $row->email,
                invitedByName: $row->inviter,
                createdAt: CarbonImmutable::parse($row->created_at)->toIso8601String(),
                expiresAt: CarbonImmutable::parse($row->expires_at)->toIso8601String(),
                expired: now()->greaterThanOrEqualTo($row->expires_at),
            ))
            ->all();
    }

    /**
     * Anyone holding a valid token. Takes no role: the new account is always
     * a student (ADR 0003 T5). Every failure gets the same answer, so a
     * token's state can't be probed.
     */
    public function accept(string $token, string $name, string $password, ?string $timezone = null, ?string $privacyVersion = null): User
    {
        $privacyVersion = app(JoiningPolicy::class)->requireAcceptance($privacyVersion);
        $pending = DB::table('invitations')->where('kind', 'email')->where('token_hash', hash('sha256', $token))->first();
        if ($pending === null || $pending->accepted_at !== null || $pending->revoked_at !== null
            || now()->greaterThanOrEqualTo($pending->expires_at)
            || User::query()->where('email', $pending->email)->exists()) {
            throw new Unprocessable('invitation_invalid', 'This invitation is not valid. Ask for a new one.');
        }

        // The factory provisions the key first, then consumes the invitation in the account transaction.
        try {
            return $this->factory->create($name, $pending->email, $password, student: true, timezone: $timezone, grantedBy: $pending->invited_by,
                afterCreate: function (User $user) use ($token, $privacyVersion) {
                    $invitation = DB::table('invitations')
                        ->where('token_hash', hash('sha256', $token))
                        ->lockForUpdate()
                        ->first();

                    if ($invitation === null
                        || $invitation->accepted_at !== null
                        || $invitation->revoked_at !== null
                        || now()->greaterThanOrEqualTo($invitation->expires_at)) {
                        throw new Unprocessable('invitation_invalid', 'This invitation is not valid. Ask for a new one.');
                    }

                    DB::table('invitations')->where('id', $invitation->id)->update([
                        'accepted_at' => now(),
                        'accepted_user_id' => $user->id,
                        'updated_at' => now(),
                    ]);
                    $user->forceFill(['privacy_version' => $privacyVersion, 'privacy_accepted_at' => now()])->save();
                    $this->audit->record($this->principals->forUser($user, 'web'), AuditAction::INVITATION_ACCEPTED, 'invitation', $invitation->id);
                });
        } catch (Conflict $e) {
            // Another acceptance can win after the preliminary read. Preserve the
            // same neutral invitation failure as an already-consumed token.
            if ($e->errorCode === 'email_taken') {
                throw new Unprocessable('invitation_invalid', 'This invitation is not valid. Ask for a new one.');
            }
            throw $e;
        }
    }

    public function classLink(Principal $by, int $places, string $expires): IssuedInvitation
    {
        Guard::protectedAdmin($by);
        Validator::make(['places' => $places, 'expires' => $expires], [
            'places' => ['required', 'integer', 'min:1', 'max:10000'],
            'expires' => ['required', 'date_format:Y-m-d H:i:s', 'after:now'],
        ])->validate();
        $id = Ids::new();
        $token = Str::random(48);
        DB::transaction(function () use ($by, $id, $token, $places, $expires) {
            DB::table('invitations')->insert([
                'id' => $id, 'kind' => 'class', 'email' => null, 'token_hash' => hash('sha256', $token),
                'places' => $places, 'uses' => 0, 'invited_by' => $by->userId, 'expires_at' => $expires,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record($by, AuditAction::INVITATION_CREATED, 'invitation', $id, role: 'admin');

        });

        return new IssuedInvitation($id, $token, CarbonImmutable::parse($expires, 'UTC')->toDateTimeImmutable());
    }

    public function classLinks(Principal $by): array
    {
        Guard::admin($by);

        return DB::table('invitations')->where('kind', 'class')->orderByDesc('created_at')->limit(200)
            ->get(['id', 'places', 'uses', 'expires_at', 'revoked_at'])->all();
    }

    public function joinClass(string $token, string $email, string $name, string $password, ?string $timezone, ?string $privacyVersion): User
    {
        $privacyVersion = app(JoiningPolicy::class)->requireAcceptance($privacyVersion);
        $pending = DB::table('invitations')->where('kind', 'class')->where('token_hash', hash('sha256', $token))->first();
        $valid = fn ($row) => $row !== null && $row->revoked_at === null && now()->lt($row->expires_at) && $row->uses < $row->places;
        if (! $valid($pending)) {
            throw new Unprocessable('invitation_invalid', 'This invitation is not valid. Ask for a new one.');
        }

        return $this->factory->create($name, $email, $password, student: true, timezone: $timezone, grantedBy: $pending->invited_by,
            afterCreate: function (User $user) use ($pending, $valid, $privacyVersion) {
                // Capacity is checked and consumed under the same lock/account transaction.
                $row = DB::table('invitations')->where('id', $pending->id)->lockForUpdate()->first();
                if (! $valid($row)) {
                    throw new Unprocessable('invitation_invalid', 'This invitation is not valid. Ask for a new one.');
                }
                DB::table('invitations')->where('id', $row->id)->increment('uses');
                $this->audit->record($this->principals->forUser($user, 'web'), AuditAction::INVITATION_ACCEPTED, 'invitation', $row->id);
                $user->forceFill(['email_verified_at' => null, 'privacy_version' => $privacyVersion, 'privacy_accepted_at' => now()])->save();
            });
    }
}
