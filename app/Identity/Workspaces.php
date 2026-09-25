<?php

namespace App\Identity;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Access\Workspace;

/**
 * Switching workspace (ADR 0003 §10.3). The adapter stores the result in the
 * session; this service decides whether the switch is allowed.
 */
final class Workspaces
{
    public function __construct(private AuditLog $audit) {}

    public function enter(Principal $by, Workspace $workspace): void
    {
        if ($workspace === Workspace::Admin) {
            // Entering the admin workspace needs 2FA and a recent password confirmation.
            Guard::protectedAdmin($by);
            $this->audit->record($by, AuditAction::WORKSPACE_ADMIN_ENTERED, 'user', $by->userId, role: 'admin');

            return;
        }

        Guard::learner($by);
    }
}
