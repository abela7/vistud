<?php

namespace App\Http;

use Closure;
use Illuminate\Support\Facades\Route;

/**
 * The /admin route group (ADR 0003 §10.3). Every admin route goes inside it,
 * so direct entry by URL gets the same checks as the workspace switch:
 * a login, the admin role (403 otherwise), confirmed 2FA, and a recent
 * password confirmation on entering the admin workspace.
 */
final class AdminRoutes
{
    public const MIDDLEWARE = ['auth', 'role:admin', 'two_factor', 'admin.workspace'];

    public static function group(Closure $routes): void
    {
        Route::middleware(self::MIDDLEWARE)->prefix('admin')->name('admin.')->group($routes);
    }
}
