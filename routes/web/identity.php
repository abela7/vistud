<?php

use App\Http\Controllers\AreaController;
use App\Http\Controllers\InvitationAcceptanceController;
use Illuminate\Support\Facades\Route;

/*
| Identity endpoints that aren't Fortify's. Owned by work package WP2.
| The pages that use them (the area switch, the invitation form) come
| from WP6.
*/

Route::post('/area/{area}', [AreaController::class, 'update'])
    ->middleware('auth')
    ->whereIn('area', ['student', 'admin'])
    ->name('area.switch');

Route::post('/invitations/accept', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['guest', 'throttle:10,1'])
    ->name('invitations.accept.store');
