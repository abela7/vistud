<?php

namespace App\Console\Commands;

use App\Identity\AccountFactory;
use App\Identity\TwoFactorReset;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Errors\AppError;
use App\Platform\Ids;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Resets an account's 2FA from the server's shell, for when no other admin
 * can (ADR 0003 §10.3, "Recovering admin access"). Audited as a system action.
 */
#[Signature('vistud:admin:reset-2fa {email}')]
#[Description("Reset an account's two-factor authentication and end its sessions")]
class ResetTwoFactor extends Command
{
    public function handle(TwoFactorReset $reset): int
    {
        $user = User::query()->where('email', AccountFactory::normaliseEmail((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->error('No account has that email address.');

            return self::FAILURE;
        }

        try {
            $reset->reset(Principal::system(requestId: Ids::new()), $user->id);
        } catch (AppError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Two-factor authentication was reset. The account must enrol again before entering the admin area.');

        return self::SUCCESS;
    }
}
