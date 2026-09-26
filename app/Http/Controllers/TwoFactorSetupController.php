<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Workspace;
use App\Providers\FortifyServiceProvider;
use App\View\QrCode;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Fortify\Fortify;

/**
 * The two-factor setup screen (WP6; ADR 0003 §10.3). Fortify's endpoints do
 * the work (enable, confirm, new recovery codes); this only shows the state.
 * Recovery codes appear once after they are generated, the same rule
 * App\Http\Middleware\ShowRecoveryCodesOnce applies to Fortify's endpoint:
 * showing them here uses up the one showing.
 */
final class TwoFactorSetupController
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $state = match (true) {
            $user->two_factor_confirmed_at !== null => 'on',
            $user->two_factor_secret !== null => 'confirming',
            default => 'off',
        };

        $recoveryCodes = $state === 'on' && $request->session()->pull(FortifyServiceProvider::RECOVERY_CODES_VIEWABLE, false)
            ? $user->recoveryCodes()
            : null;

        $secret = $state === 'confirming' ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null;

        return view('auth.two-factor-setup', [
            // Shown inside whichever workspace the account is in.
            'area' => $request->session()->get(PrincipalFactory::WORKSPACE_KEY) === Workspace::Admin->value ? 'admin' : 'student',
            'state' => $state,
            'secret' => $secret,
            'qrCode' => $state === 'confirming' ? QrCode::svg($user->twoFactorQrCodeUrl(), 'QR code for your authenticator app') : null,
            'recoveryCodes' => $recoveryCodes,
        ]);
    }
}
