<?php

namespace App\Audit;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Http\RequestId;
use App\Platform\Ids;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The append-only audit log (ADR 0003 §10.4). There is deliberately no
 * method that changes or removes a record, and the runtime database user
 * could not run one anyway.
 */
final class AuditLog
{
    /**
     * Metadata holds IDs, codes and flags only. Strings must be ID tokens,
     * which rules out email addresses and free text. $role is the role the
     * actor acted in, and defaults to Principal::auditRole().
     *
     * @param  array<string, bool|int|string|null|list<bool|int|string|null>>  $metadata
     */
    public function record(
        Principal $actor,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
        ?string $role = null,
    ): void {
        self::assertSafe($metadata);

        DB::table('audit_log')->insert([
            'occurred_at' => now()->format('Y-m-d H:i:s.u'),
            'actor_type' => $actor->isSystem() ? 'system' : 'user',
            'actor_user_id' => $actor->userId,
            'actor_role' => $role ?? $actor->auditRole(),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'ip' => $actor->ip,
            'user_agent' => $actor->userAgent === null ? null : mb_substr($actor->userAgent, 0, 255),
            'request_id' => $actor->requestId ?? RequestId::of(),
        ]);
    }

    /**
     * Newest first. Admins only, with confirmed 2FA.
     *
     * @param  array{action?: string, target_type?: string, target_id?: string, actor_user_id?: string}  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function list(Principal $by, array $filters = [], ?string $cursor = null, int $limit = 50): array
    {
        Guard::admin($by);

        $limit = max(1, min($limit, 200));
        $query = DB::table('audit_log')->orderByDesc('id')->limit($limit + 1);
        foreach (['action', 'target_type', 'target_id', 'actor_user_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if ($cursor !== null && ctype_digit($cursor)) {
            $query->where('id', '<', (int) $cursor);
        }

        $rows = $query->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'occurred_at' => $row->occurred_at,
            'actor_type' => $row->actor_type,
            'actor_user_id' => $row->actor_user_id,
            'actor_role' => $row->actor_role,
            'action' => $row->action,
            'target_type' => $row->target_type,
            'target_id' => $row->target_id,
            'metadata' => $row->metadata === null ? null : json_decode($row->metadata, true),
            'ip' => $row->ip,
            'request_id' => $row->request_id,
        ])->all();

        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $next = (string) end($rows)['id'];
        }

        return ['data' => $rows, 'next_cursor' => $next];
    }

    private static function assertSafe(array $metadata): void
    {
        array_walk_recursive($metadata, function ($value, $key) {
            if (is_string($value) && ! Ids::isToken($value)) {
                throw new InvalidArgumentException("Audit metadata '{$key}' must be an ID or code, never free text.");
            }
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("Audit metadata '{$key}' must be scalar.");
            }
        });
    }
}
