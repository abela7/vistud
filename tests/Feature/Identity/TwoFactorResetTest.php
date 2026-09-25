<?php

namespace Tests\Feature\Identity;

use App\Audit\AuditAction;
use App\Identity\TwoFactorReset;
use App\Platform\Access\Principal;
use App\Platform\Errors\PasswordConfirmationRequired;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** ADR 0003 §10.3, "Recovering admin access". */
class TwoFactorResetTest extends TestCase
{
    use CreatesAccounts, RefreshesDatabase;

    public function test_another_admin_resets_2fa_and_ends_the_sessions(): void
    {
        $admin = $this->admin();
        $target = $this->admin();
        DB::table('sessions')->insert(['id' => 'sess-2', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);

        app(TwoFactorReset::class)->reset($this->principal($admin), $target->id);

        $target->refresh();
        $this->assertNull($target->two_factor_secret);
        $this->assertNull($target->two_factor_confirmed_at);
        $this->assertFalse(DB::table('sessions')->where('user_id', $target->id)->exists());
        [$audit] = $this->auditRows(AuditAction::TWO_FACTOR_RESET);
        $this->assertSame([$admin->id, 'admin'], [$audit->actor_user_id, $audit->actor_role]);
    }

    public function test_resetting_needs_a_recent_confirmation_or_the_console(): void
    {
        $admin = $this->admin();
        $target = $this->admin();

        $this->assertThrows(fn () => app(TwoFactorReset::class)->reset($this->principal($admin, confirmed: false), $target->id), PasswordConfirmationRequired::class);

        app(TwoFactorReset::class)->reset(Principal::system(), $target->id);
        [$audit] = $this->auditRows(AuditAction::TWO_FACTOR_RESET);
        $this->assertSame('system', $audit->actor_role);
    }
}
