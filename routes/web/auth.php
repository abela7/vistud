<?php

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
});

Route::middleware('auth')->group(function () {
    // Fortify handles POST /user/confirm-password and then returns the user
    // to the page they were trying to open.
    Route::view('/user/confirm-password', 'auth.confirm-password')->name('password.confirm');
});
