<?php

namespace App\Mcp;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use Illuminate\Support\Facades\DB;

/**
 * The AI apps a student let into ViStud over MCP (docs/specs/mcp-connections-plan.md §3.2, §3.4). A row is written
 * only when the student approves the app on ViStud's own consent page; a token whose app has no live row is refused.
 * Cutting a connection revokes its tokens at once, so the app's next call answers 401 and it must sign in again.
 */
final class Connections
{
    /** @var array<string, string> what a connected app may do, chosen on the consent page */
    public const LEVELS = [
        'read' => 'Read only',
        'write' => 'Read and write',
        'full' => 'Read, write, and changes you confirm',
    ];

    public const KIND = 'mcp';

    public function __construct(private AuditLog $audit) {}

    /** Records the student's approval of an app, or renews it; the level is theirs to choose each time. */
    public function consent(Principal $by, string $clientId, string $level): ConnectionDetails
    {
        $scope = Guard::learner($by);
        if (! isset(self::LEVELS[$level])) {
            throw new Unprocessable('validation_failed', 'Some fields need attention.', ['fields' => ['level' => ['Choose what the app may do.']]]);
        }
        $client = DB::table('oauth_clients')->where('id', $clientId)->where('revoked', false)->first() ?? throw new NotFound;
        $redirect = self::firstRedirect($client->redirect_uris);
        $provider = Provider::fromRedirect($redirect);

        $id = DB::transaction(function () use ($scope, $clientId, $level, $client, $redirect, $provider) {
            $row = LearnerTables::query($scope, 'mcp_connections')->where('oauth_client_id', $clientId)->lockForUpdate()->first();
            $fields = [
                'kind' => self::KIND,
                'provider' => $provider,
                'client_label' => mb_substr((string) $client->name, 0, 128),
                'redirect_host' => mb_substr((string) parse_url($redirect, PHP_URL_HOST), 0, 255),
                'level' => $level,
                'consented_at' => now(),
                'revoked_at' => null,
                'revoked_reason' => null,
                'updated_at' => now(),
            ];
            if ($row !== null) {
                LearnerTables::query($scope, 'mcp_connections')->where('id', $row->id)->update($fields);

                return (string) $row->id;
            }
            $id = Ids::new();
            LearnerTables::insert($scope, 'mcp_connections', ['id' => $id, 'oauth_client_id' => $clientId, 'created_at' => now()] + $fields);

            return $id;
        });

        $this->audit->record($by, AuditAction::CONNECTION_CONSENTED, 'mcp_connection', $id, ['provider' => $provider, 'level' => $level]);

        return $this->find($by, $id);
    }

    /** The live connection for this app, or null: fail closed. */
    public static function live(LearnerScope $scope, string $clientId): ?object
    {
        return LearnerTables::query($scope, 'mcp_connections')
            ->where('oauth_client_id', $clientId)
            ->whereNull('revoked_at')
            ->first();
    }

    /** Marks the connection used, at most once a minute, so a busy chat doesn't write on every call. */
    public static function touch(LearnerScope $scope, object $row): void
    {
        if ($row->last_used_at !== null && now()->diffInSeconds($row->last_used_at, true) < 60) {
            return;
        }
        LearnerTables::query($scope, 'mcp_connections')->where('id', $row->id)->update(['last_used_at' => now()]);
    }

    /** @return list<ConnectionDetails> live ones first, newest first */
    public function list(Principal $by): array
    {
        $scope = Guard::learner($by);

        return LearnerTables::query($scope, 'mcp_connections')
            ->orderByRaw('revoked_at is not null')
            ->orderByDesc('consented_at')
            ->get()
            ->map(fn (object $row) => self::details($row))
            ->all();
    }

    public function find(Principal $by, string $id): ConnectionDetails
    {
        $scope = Guard::learner($by);

        return self::details(LearnerTables::query($scope, 'mcp_connections')->where('id', $id)->first() ?? throw new NotFound);
    }

    /** The student cuts one app off. Its tokens stop working at once. */
    public function disconnect(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $row = LearnerTables::query($scope, 'mcp_connections')->where('id', $id)->first() ?? throw new NotFound;
        if ($row->revoked_at !== null) {
            return;
        }
        self::revoke($scope, $by->userId, $row, 'student');
        $this->audit->record($by, AuditAction::CONNECTION_REVOKED, 'mcp_connection', (string) $row->id, ['reason' => 'student']);
    }

    /** The student cuts every app off. Returns how many were live. */
    public function disconnectAll(Principal $by): int
    {
        $scope = Guard::learner($by);
        $count = 0;
        foreach (LearnerTables::query($scope, 'mcp_connections')->whereNull('revoked_at')->get() as $row) {
            self::revoke($scope, $by->userId, $row, 'student');
            $this->audit->record($by, AuditAction::CONNECTION_REVOKED, 'mcp_connection', (string) $row->id, ['reason' => 'student_all']);
            $count++;
        }

        return $count;
    }

    /**
     * Cuts every app of one account after an account event (a suspension, a deletion request, a 2FA reset, a new
     * password). Only trusted server code calls it (App\Jobs\RevokeConnections). Returns how many were live.
     */
    public static function revokeEverything(LearnerScope $scope, string $userId, string $reason): int
    {
        $count = 0;
        foreach (LearnerTables::query($scope, 'mcp_connections')->whereNull('revoked_at')->get() as $row) {
            self::revoke($scope, $userId, $row, $reason);
            $count++;
        }
        // Tokens of any app, even one with no connection row, die with the account event.
        self::revokeTokens($userId, null);

        return $count;
    }

    /** Revokes one app's access tokens, refresh tokens and unused codes for this account, or every app's when $clientId is null. */
    public static function revokeTokens(string $userId, ?string $clientId): void
    {
        $ids = DB::table('oauth_access_tokens')->where('user_id', $userId)
            ->when($clientId !== null, fn ($q) => $q->where('client_id', $clientId))
            ->pluck('id')->all();
        if ($ids !== []) {
            DB::table('oauth_access_tokens')->whereIn('id', $ids)->update(['revoked' => true, 'updated_at' => now()]);
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $ids)->update(['revoked' => true]);
        }
        DB::table('oauth_auth_codes')->where('user_id', $userId)
            ->when($clientId !== null, fn ($q) => $q->where('client_id', $clientId))
            ->update(['revoked' => true]);
    }

    /** Cuts one connection for trusted server code (the nightly upkeep, vistud:mcp:prune). */
    public static function revokeOne(LearnerScope $scope, string $userId, object $row, string $reason): void
    {
        self::revoke($scope, $userId, $row, $reason);
    }

    private static function revoke(LearnerScope $scope, ?string $userId, object $row, string $reason): void
    {
        DB::transaction(function () use ($scope, $userId, $row, $reason) {
            LearnerTables::query($scope, 'mcp_connections')->where('id', $row->id)->update([
                'revoked_at' => now(),
                'revoked_reason' => $reason,
                'updated_at' => now(),
            ]);
            // A change still waiting for the student's Yes dies with the connection (M6.5).
            LearnerTables::query($scope, 'mcp_confirmations')->where('connection_id', $row->id)->whereNull('used_at')->delete();
            if ($userId !== null) {
                self::revokeTokens($userId, (string) $row->oauth_client_id);
            }
        });
    }

    private static function firstRedirect(mixed $uris): string
    {
        $list = is_string($uris) ? json_decode($uris, true) : $uris;
        if (is_array($list) && isset($list[0]) && is_string($list[0])) {
            return $list[0];
        }

        return is_string($uris) ? $uris : '';
    }

    private static function details(object $row): ConnectionDetails
    {
        return new ConnectionDetails(
            id: (string) $row->id,
            oauthClientId: (string) $row->oauth_client_id,
            provider: (string) $row->provider,
            clientLabel: $row->client_label,
            redirectHost: $row->redirect_host,
            level: (string) $row->level,
            consentedAt: (string) $row->consented_at,
            revokedAt: $row->revoked_at !== null ? (string) $row->revoked_at : null,
            lastUsedAt: $row->last_used_at !== null ? (string) $row->last_used_at : null,
        );
    }
}
