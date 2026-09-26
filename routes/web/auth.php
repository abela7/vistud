<?php

use App\Http\Controllers\TwoFactorSetupController;
use Illuminate\Support\Facades\Route;

/*
| Signed-out screens (work package WP6). Fortify's views stay off: each
| screen is registered here as it is built, and Fortify keeps handling the
| POST endpoints (docs/handoff/m1-work-packages.md).
*/

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');

    // Fortify handles POST /two-factor-challenge. This GET exists only while
    // a password login is waiting for a second factor (session "login.id").
    Route::get('/two-factor-challenge', function () {
        if (! session()->has('login.id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    })->name('two-factor.login');

    Route::view('/forgot-password', 'auth.forgot-password')->name('password.request');

    Route::get('/reset-password/{token}', function (string $token) {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => request()->query('email', ''),
        ]);
    })->name('password.reset');
});

// Accepting an invitation. The link carries its token after "#", which the
// browser keeps to itself, so the token never appears in a server log; the
// page's script moves it into the form (POST /invitations/accept).
Route::view('/invitation', 'auth.accept-invitation')->name('invitations.show');

Route::middleware('auth')->group(function () {
    // Fortify handles POST /user/confirm-password and then returns the user
    // to the page they were trying to open.
    Route::view('/user/confirm-password', 'auth.confirm-password')->name('password.confirm');

    // Turning on two-factor authentication. A fresh password confirmation
    // comes first, as Fortify's own two-factor endpoints require one.
    Route::get('/user/two-factor', TwoFactorSetupController::class)
        ->middleware('password.confirm')
        ->name('two-factor.setup');
});
