<?php

namespace App\Platform\Http\Middleware;

use App\Platform\Http\RequestId;
use App\Platform\Ids;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an ID: the caller's X-Request-Id when it is a safe
 * token, otherwise a new UUIDv7. The ID goes into the log context, the
 * audit log and the X-Request-Id response header.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get('X-Request-Id');
        $id = is_string($incoming) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming) === 1
            ? $incoming
            : Ids::new();

        $request->attributes->set(RequestId::ATTRIBUTE, $id);
        Context::add(RequestId::ATTRIBUTE, $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
