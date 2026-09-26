<?php

namespace App\Audit;

/** Audit action names (docs/architecture/contracts.md#audit-actions). */
final class AuditAction
{
    public const ACCOUNT_CREATED = 'account.created';

    public const ACCOUNT_SUSPENDED = 'account.suspended';

    public const ACCOUNT_REACTIVATED = 'account.reactivated';

    public const ACCOUNT_DELETION_REQUESTED = 'account.deletion_requested';

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
}
