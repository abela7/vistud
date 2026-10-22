<?php

namespace App\Engine;

use App\Identity\AdmissionGate;
use App\Platform\Access\Principal;
use App\Platform\Access\Role;
use App\Platform\Ids;

/** A non-admin with no key of their own is never given the installation key (at most the shared key for students). */
final class StudentKeyGate
{
    public function check(): bool
    {
        return app(AdmissionGate::class)->ownKeyOnly(new Principal(Ids::new(), Ids::new(), [Role::Student], true, null, 'console'));
    }
}
