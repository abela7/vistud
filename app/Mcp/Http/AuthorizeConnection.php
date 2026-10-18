<?php

namespace App\Mcp\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport's GET /oauth/authorize, with ViStud's two rules (docs/specs/mcp-connections-plan.md §3.2): the consent
 * page is never skipped, even when the student already holds a live token for the app, and it may not be framed by
 * another site (a click-jacked Approve would hand over the student's account).
 */
final class AuthorizeConnection extends AuthorizationController
{
    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse
    ): Response|AuthorizationViewResponse {
        $response = parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
        if ($response instanceof Responsable) {
            $response = $response->toResponse($request);
        }
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");

        return $response;
    }

    /** Never: every sign-in shows the consent page. */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
