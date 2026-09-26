<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;

/**
 * The same answer whether or not the email belongs to an account, for
 * browsers and JSON clients alike. Fortify's own responses differ ("we have
 * emailed…" against "no account…"), which would let anyone look people up.
 */
class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponseContract, SuccessfulPasswordResetLinkRequestResponseContract
{
    public const MESSAGE = "If that email has an account, we've sent a link to reset the password.";

    public function __construct(string $status)
    {
        // $status is the broker key (for example passwords.user). It is not shown.
        unset($status);
    }

    public function toResponse($request)
    {
        return $request->wantsJson()
            ? new JsonResponse(['message' => self::MESSAGE], 200)
            : back()->with('status', self::MESSAGE);
    }
}
