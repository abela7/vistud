<?php

namespace App\Http\Controllers;

use App\Identity\Invitations;
use App\Platform\Errors\Unprocessable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepting an invitation creates a student account and logs it in. Only
 * the validated fields reach the service; a `role` field is ignored
 * (ADR 0003 T5).
 */
class InvitationAcceptanceController
{
    public function store(Request $request, Invitations $invitations): Response
    {
        $input = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $user = $invitations->accept($input['token'], $input['name'], $input['password'], $input['timezone'] ?? null);
        } catch (Unprocessable $e) {
            if ($request->expectsJson() || $e->errorCode !== 'invitation_invalid') {
                throw $e;
            }

            // The page shows its "this link doesn't work" answer. No input is
            // kept: there is nothing left to correct.
            return redirect()->route('invitations.show')->withErrors(['invitation' => $e->getMessage()]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return response()->json(['id' => $user->id], 201);
        }

        return redirect('/')->with('status', 'invitation-accepted');
    }
}
