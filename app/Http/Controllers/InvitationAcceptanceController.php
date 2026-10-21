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
            'privacy' => ['required', 'accepted'],
            'privacy_version' => ['required', 'string', 'size:64'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
        ]);

        try {
            $user = $request->routeIs('class-links.accept')
                ? $invitations->joinClass($input['token'], $request->validate(['email' => ['required', 'email', 'max:255']])['email'], $input['name'], $input['password'], $input['timezone'] ?? null, $input['privacy_version'])
                : $invitations->accept($input['token'], $input['name'], $input['password'], $input['timezone'] ?? null, $input['privacy_version']);
        } catch (Unprocessable $e) {
            if ($request->expectsJson() || $e->errorCode !== 'invitation_invalid') {
                throw $e;
            }

            // The page shows its "this link doesn't work" answer. No input is
            // kept: there is nothing left to correct.
            return redirect()->route($request->routeIs('class-links.accept') ? 'class-links.show' : 'invitations.show')->withErrors(['invitation' => $e->getMessage()]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        if ($request->expectsJson()) {
            return response()->json(['id' => $user->id], 201);
        }

        return redirect()->route($user->hasVerifiedEmail() ? 'joining.key' : 'verification.notice');
    }
}
