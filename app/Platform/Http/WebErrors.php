<?php

namespace App\Platform\Http;

use App\Platform\Errors\AppError;
use App\Platform\Errors\PasswordConfirmationRequired;
use App\Platform\Errors\TwoFactorRequired;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * How an AppError looks in a browser page (not the JSON API). Missing and
 * forbidden records use the standard error views, which the web work package
 * styles; confirmation and 2FA errors send the user where they can fix them.
 */
final class WebErrors
{
    public const TWO_FACTOR_SETUP_ROUTE = 'two-factor.setup';

    public static function render(AppError $e, Request $request): Response
    {
        if ($e instanceof PasswordConfirmationRequired && Route::has('password.confirm')) {
            return redirect()->guest(route('password.confirm'));
        }
        if ($e instanceof TwoFactorRequired && Route::has(self::TWO_FACTOR_SETUP_ROUTE)) {
            return redirect()->route(self::TWO_FACTOR_SETUP_ROUTE);
        }

        return app(ExceptionHandler::class)->render($request, new HttpException($e->status(), $e->getMessage(), $e));
    }
}
