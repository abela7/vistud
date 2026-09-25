<?php

use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\EnterAdminWorkspace;
use App\Http\Middleware\RequirePasswordConfirmation;
use App\Http\Middleware\ShowRecoveryCodesOnce;
use App\Platform\Errors\AppError;
use App\Platform\Http\ErrorEnvelope;
use App\Platform\Http\Middleware\AddAccountHeader;
use App\Platform\Http\Middleware\AssignRequestId;
use App\Platform\Http\WebErrors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$wantsJson = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // The JSON API. It runs on the web middleware group (session
            // cookies and CSRF), as ADR 0003 §12 decides; Passport-based
            // external clients arrive in M6 on the same controllers.
            Route::middleware(['web', 'api.v1'])
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(base_path('routes/api/v1.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->appendToGroup('web', [EnsureAccountActive::class, ShowRecoveryCodesOnce::class]);
        $middleware->group('api.v1', [AddAccountHeader::class]);
        $middleware->alias([
            'role' => EnsureRole::class,
            'two_factor' => EnsureTwoFactorEnrolled::class,
            'admin.workspace' => EnterAdminWorkspace::class,
            'password.confirm' => RequirePasswordConfirmation::class,
        ]);
        // Login pages arrive with work package WP6. Until then, guests go home.
        $middleware->redirectGuestsTo(fn () => Route::has('login') ? route('login') : '/');
    })
    ->withExceptions(function (Exceptions $exceptions) use ($wantsJson): void {
        // Expected failures reported to callers are not application errors.
        $exceptions->dontReport([AppError::class]);

        $exceptions->shouldRenderJsonWhen($wantsJson);

        $exceptions->render(function (Throwable $e, Request $request) use ($wantsJson) {
            if ($e instanceof HttpResponseException) {
                return null;
            }
            if ($wantsJson($request)) {
                return ErrorEnvelope::render($e, $request);
            }
            if ($e instanceof AppError) {
                return WebErrors::render($e, $request);
            }

            return null;
        });
    })->create();
