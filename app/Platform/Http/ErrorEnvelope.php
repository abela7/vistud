<?php

namespace App\Platform\Http;

use App\Platform\Errors\AppError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The JSON error envelope (docs/architecture/conventions.md). Stable.
 *
 *     { "error": { "code": "...", "message": "...", "request_id": "...", "details": { ... } } }
 *
 * `details` is present only when there is something to say. Messages are
 * generic and never echo request values or stored content.
 */
final class ErrorEnvelope
{
    private const GENERIC = [
        400 => ['bad_request', 'The request could not be understood.'],
        401 => ['unauthenticated', 'Log in to continue.'],
        403 => ['forbidden', 'You do not have permission to do this.'],
        404 => ['not_found', 'Not found.'],
        405 => ['method_not_allowed', 'This method is not allowed here.'],
        409 => ['conflict', 'The request conflicts with the current state.'],
        410 => ['gone', 'This item is no longer available.'],
        413 => ['too_large', 'The request is too large.'],
        419 => ['session_expired', 'Your session has expired. Log in again.'],
        422 => ['validation_failed', 'The request is invalid.'],
        423 => ['password_confirmation_required', 'Confirm your password to continue.'],
        429 => ['rate_limited', 'Too many requests. Try again shortly.'],
        503 => ['unavailable', 'The service is temporarily unavailable.'],
    ];

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $message, $details] = self::describe($e);

        $error = ['code' => $code, 'message' => $message, 'request_id' => RequestId::of($request)];
        if ($details !== []) {
            $error['details'] = $details;
        }

        $headers = [];
        if ($e instanceof HttpExceptionInterface) {
            $headers = array_intersect_key($e->getHeaders(), array_flip(['Retry-After', 'Allow']));
        }

        return new JsonResponse(['error' => $error], $status, $headers);
    }

    /** @return array{0: int, 1: string, 2: string, 3: array<string, mixed>} status, code, message, details */
    public static function describe(Throwable $e): array
    {
        if ($e instanceof AppError) {
            return [$e->status(), $e->errorCode, $e->getMessage(), $e->details];
        }
        if ($e instanceof ValidationException) {
            return [422, 'validation_failed', 'The request is invalid.', ['fields' => $e->errors()]];
        }
        if ($e instanceof AuthenticationException) {
            return [401, ...self::GENERIC[401], []];
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            [$code, $message] = self::GENERIC[$status] ?? ($status >= 500
                ? ['server_error', 'Something went wrong.']
                : ['http_'.$status, 'The request could not be completed.']);

            return [$status, $code, $message, []];
        }

        return [500, 'server_error', 'Something went wrong.', []];
    }
}
