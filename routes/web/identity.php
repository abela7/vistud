<?php

use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
| Identity endpoints that aren't Fortify's. Owned by work package WP2.
| The pages that use them (the workspace switch, the invitation form) come
| from WP6.
*/

Route::post('/workspace/{workspace}', [WorkspaceController::class, 'update'])
    ->middleware('auth')
    ->whereIn('workspace', ['student', 'admin'])
    ->name('workspace.switch');

Route::post('/invitations/accept', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['guest', 'throttle:10,1'])
    ->name('invitations.accept.store');
