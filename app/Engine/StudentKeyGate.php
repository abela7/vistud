<?php

namespace App\Engine;

use App\Platform\Access\Principal;
use App\Platform\Access\Role;
use App\Platform\Ids;

/** A non-admin with no key of their own is never given the installation key. */
final class StudentKeyGate
{
    public function check(): bool
    {
        $student = new Principal(Ids::new(), Ids::new(), [Role::Student], true, null, 'console');

        return app(Settings::class)->credential($student) === '';
    }
}
