<?php

namespace App\Audit;

/** Audit action names (docs/architecture/contracts.md#audit-actions). */
final class AuditAction
{
    public const ACCOUNT_CREATED = 'account.created';

    public const ACCOUNT_SUSPENDED = 'account.suspended';

    public const ACCOUNT_REACTIVATED = 'account.reactivated';

    public const ACCOUNT_DELETION_REQUESTED = 'account.deletion_requested';

    public const ACCOUNT_TIMEZONE_CHANGED = 'account.timezone_changed';

    public const INVITATION_CREATED = 'invitation.created';

    public const INVITATION_REVOKED = 'invitation.revoked';

    public const INVITATION_ACCEPTED = 'invitation.accepted';

    public const ROLE_GRANTED = 'role.granted';

    public const ROLE_REVOKED = 'role.revoked';

    public const TWO_FACTOR_RESET = 'two_factor.reset';

    public const TWO_FACTOR_CONFIRMED = 'two_factor.confirmed';

    public const TWO_FACTOR_DISABLED = 'two_factor.disabled';

    public const RECOVERY_CODES_GENERATED = 'two_factor.recovery_codes_generated';

    public const RECOVERY_CODE_USED = 'two_factor.recovery_code_used';

    public const ADMIN_AREA_ENTERED = 'area.admin_entered';

    public const ADMIN_ACCESS_DENIED = 'admin.access_denied';

    public const ENGINE_KEY_SET = 'engine.key_set';

    public const ENGINE_KEY_REMOVED = 'engine.key_removed';

    public const ENGINE_DEFAULTS_CHANGED = 'engine.defaults_changed';

    // AI apps connected over MCP (docs/specs/mcp-connections-plan.md §3.4). Lifecycle and exceptions only: never a
    // per-call row, since this log is read by admins and kept for good.
    public const CONNECTION_CONSENTED = 'connection.consented';

    public const CONNECTION_REVOKED = 'connection.revoked';

    public const CONNECTION_DENIED = 'connection.denied';

    public const MCP_RATE_LIMITED = 'mcp.rate_limited';

    // A change a chat app asked for, which the student confirmed on a ViStud page (the tool name only, never the change).
    public const MCP_DESTRUCTIVE_CONFIRMED = 'mcp.destructive_confirmed';
}
