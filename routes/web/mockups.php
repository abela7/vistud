<?php

use Illuminate\Support\Facades\Route;

/*
| Design mockups for the workspaces plan (docs/specs/workspaces.md): static
| screens built from the real components and theme, for the owner's review.
| Loaded only in local development (routes/web.php), never in production or
| tests. Remove them once the real M2 screens exist.
*/

Route::middleware('auth')->prefix('_mockups/workspaces')->group(function () {
    Route::view('/', 'mockups.workspaces.home');
    Route::view('/biology', 'mockups.workspaces.overview');
    Route::view('/biology/modules', 'mockups.workspaces.modules');
    Route::view('/biology/notes/mitosis-summary', 'mockups.workspaces.note');
    Route::view('/biology/progress', 'mockups.workspaces.progress');
});
