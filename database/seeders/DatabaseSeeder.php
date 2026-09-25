<?php

namespace Database\Seeders;

use App\Identity\Accounts;
use App\Identity\Roles;
use App\Platform\Access\Principal;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local development accounts only (ADR 0003 D4: the owner plus synthetic
 * accounts). Created through the Identity services, so they are audited
 * like console actions. Never runs in production.
 *
 *   owner@vistud.test      student and admin (set up 2FA before entering admin)
 *   student.a@vistud.test  student
 *   student.b@vistud.test  student
 *
 * Every password is "local-password-only".
 */
class DatabaseSeeder extends Seeder
{
    public function run(Accounts $accounts, Roles $roles): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The development seeder never runs in production.');
        }

        $system = Principal::system();
        $password = 'local-password-only';

        $owner = $accounts->create($system, 'Owner', 'owner@vistud.test', $password, timezone: 'Europe/London');
        $roles->grantAdmin($system, $owner->id);

        $accounts->create($system, 'Synthetic Student A', 'student.a@vistud.test', $password, timezone: 'Europe/London');
        $accounts->create($system, 'Synthetic Student B', 'student.b@vistud.test', $password, timezone: 'Europe/London');
    }
}
