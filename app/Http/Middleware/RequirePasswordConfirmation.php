<?php

namespace App\Http\Middleware;

use App\Platform\Errors\PasswordConfirmationRequired;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;

/**
 * Laravel's `password.confirm` middleware, answering JSON requests with the
 * error envelope (423 password_confirmation_required) instead of its own
 * format. Browser requests are redirected to the confirm-password page as usual.
 */
class RequirePasswordConfirmation extends RequirePassword
{
    public function __construct(ResponseFactory $responseFactory, UrlGenerator $urlGenerator)
    {
        parent::__construct($responseFactory, $urlGenerator, (int) config('auth.password_timeout', 600));
    }

    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        if ($request->expectsJson() && $this->shouldConfirmPassword($request, $passwordTimeoutSeconds)) {
            throw new PasswordConfirmationRequired;
        }

        return parent::handle($request, $next, $redirectToRoute, $passwordTimeoutSeconds);
    }
}
