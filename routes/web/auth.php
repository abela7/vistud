<?php

use App\Engine\Settings;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\TwoFactorSetupController;
use App\Identity\JoiningPolicy;
use App\Identity\PrincipalFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

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
Route::view('/class-link', 'auth.accept-invitation', ['classLink' => true])->name('class-links.show');
Route::post('/class-links/accept', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['guest', 'throttle:joining'])->name('class-links.accept');
Route::view('/email/verify', 'auth.verify-email')->middleware('auth')->name('verification.notice');
Route::get('/privacy', function (JoiningPolicy $policy) {
    abort_if($policy->version() === null, 404);

    return view('auth.privacy', ['policy' => Str::markdown($policy->text(), ['html_input' => 'strip', 'allow_unsafe_links' => false])]);
})->name('privacy');
Route::get('/join/key', function (Settings $settings, PrincipalFactory $principals) {
    $by = $principals->fromRequest(request());

    return $settings->keyAvailable($by) ? redirect()->route('home') : view('auth.add-key');
})->middleware(['auth', 'verified'])->name('joining.key');
Route::post('/join/key', function (Request $request, Settings $settings, PrincipalFactory $principals) {
    $input = $request->validate(['key' => ['required', 'string', 'max:300']]);
    $settings->setKey($principals->fromRequest($request), $input['key']);

    return redirect()->route('home');
})->middleware(['auth', 'verified', 'throttle:10,1'])->name('joining.key.store');

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
