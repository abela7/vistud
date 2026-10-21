<?php

namespace App\Http\Controllers;

use App\Identity\Accounts;
use App\Identity\PrincipalFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class DeleteAccountController
{
    public function __invoke(Request $request, Accounts $accounts, PrincipalFactory $principals): Response
    {
        $request->validate(['confirm_deletion' => ['accepted']]);
        $by = $principals->fromRequest($request);
        $accounts->requestDeletion($by, (string) $by->userId);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->view('account-deleted')->header('Cache-Control', 'private, no-store');
    }
}
