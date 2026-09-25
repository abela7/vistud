<?php

namespace App\Console\Commands;

use App\Identity\AccountFactory;
use App\Identity\Roles;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Ids;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Grants admin from the server's shell (ADR 0003 §10.3, first-admin setup
 * step 2). There is no web route for the first admin. Audited as a system
 * action. The new admin must enrol in 2FA before entering the admin workspace.
 */
#[Signature('vistud:admin:grant {email}')]
#[Description('Grant the admin role to an existing account')]
class GrantAdmin extends Command
{
    public function handle(Roles $roles): int
    {
        $user = User::query()->where('email', AccountFactory::normaliseEmail((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->error('No account has that email address.');

            return self::FAILURE;
        }

        try {
            $roles->grantAdmin(Principal::system(requestId: Ids::new()), $user->id);
        } catch (AppError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Granted admin. The account must set up two-factor authentication before entering the admin workspace.');

        return self::SUCCESS;
    }
}
