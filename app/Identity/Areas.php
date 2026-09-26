<?php

namespace App\Identity;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Platform\Access\Area;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;

/**
 * Switching area (ADR 0003 §10.3). The adapter stores the result in the
 * session; this service decides whether the switch is allowed.
 */
final class Areas
{
    public function __construct(private AuditLog $audit) {}

    public function enter(Principal $by, Area $area): void
    {
        if ($area === Area::Admin) {
            // Entering the admin area needs 2FA and a recent password confirmation.
            Guard::protectedAdmin($by);
            $this->audit->record($by, AuditAction::ADMIN_AREA_ENTERED, 'user', $by->userId, role: 'admin');

            return;
        }

        Guard::learner($by);
    }
}
