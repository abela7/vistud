<?php

use Illuminate\Support\Facades\Route;

/*
| Signed-out screens (work package WP6). Fortify's views stay off: each
| screen is registered here as it is built, and Fortify keeps handling the
| POST endpoints (docs/handoff/m1-work-packages.md).
*/

Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
});
