<?php

namespace App\Providers;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Identity\AccountFactory;
use App\Identity\AccountStatus;
use App\Identity\Fortify\ResetUserPassword;
use App\Identity\Fortify\UpdateUserPassword;
use App\Identity\PrincipalFactory;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;

/**
 * Fortify's authentication backend (ADR 0003 §10.3). Views are registered
 * separately by work package WP6.
 */
class FortifyServiceProvider extends ServiceProvider
{
    /** Session flag: recovery codes were generated in this session and may be shown once. */
    public const RECOVERY_CODES_VIEWABLE = 'vistud.recovery_codes_viewable';

    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        // Only active accounts can log in. Every failure looks the same, so
        // an account's status can't be probed from the login form.
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::query()->where('email', AccountFactory::normaliseEmail((string) $request->input('email')))->first();

            return $user !== null
                && $user->status === AccountStatus::Active->value
                && Hash::check((string) $request->input('password'), $user->password)
                ? $user
                : null;
        });

        RateLimiter::for('login', function (Request $request) {
            $key = mb_strtolower((string) $request->input(Fortify::username())).'|'.$request->ip();

            return Limit::perMinute(5)->by(hash('sha256', $key));
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id'));
        });

        $this->auditTwoFactorChanges();
    }

    private function auditTwoFactorChanges(): void
    {
        $record = function (User $user, string $action) {
            $request = request();
            $principal = app(PrincipalFactory::class)->forUser(
                $user,
                'web',
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );
            app(AuditLog::class)->record($principal, $action, 'user', $user->id);
        };

        Event::listen(TwoFactorAuthenticationConfirmed::class, fn ($e) => $record($e->user, AuditAction::TWO_FACTOR_CONFIRMED));
        Event::listen(TwoFactorAuthenticationDisabled::class, fn ($e) => $record($e->user, AuditAction::TWO_FACTOR_DISABLED));
        Event::listen(RecoveryCodeReplaced::class, fn ($e) => $record($e->user, AuditAction::RECOVERY_CODE_USED));

        // Codes are generated when 2FA is first enabled and when they are
        // regenerated. Either way, they may be shown once in this session.
        $viewable = function () {
            if (request()->hasSession()) {
                request()->session()->put(self::RECOVERY_CODES_VIEWABLE, true);
            }
        };
        Event::listen(TwoFactorAuthenticationEnabled::class, fn () => $viewable());
        Event::listen(RecoveryCodesGenerated::class, function ($e) use ($record, $viewable) {
            $record($e->user, AuditAction::RECOVERY_CODES_GENERATED);
            $viewable();
        });
    }
}
