<?php

use App\Http\AdminRoutes;
use App\Platform\Errors\NotFound;
use Illuminate\Support\Facades\Route;

/*
| The admin workspace. Owned by work package WP2 (the protected group);
| screens come from WP6 in routes/web/admin-screens.php.
|
| The fallback is matched after every other route, whenever it was
| registered: any other /admin URL answers 403 to non-admins, like every
| admin route, and 404 to admins.
*/

AdminRoutes::group(function () {
    if (file_exists(__DIR__.'/admin-screens.php')) {
        require __DIR__.'/admin-screens.php';
    }

    Route::any('{path?}', fn () => throw new NotFound)->where('path', '.*')->fallback()->name('fallback');
});
