<?php

namespace App\Mcp\Http;

use App\Identity\PrincipalFactory;
use App\Mcp\Connections;
use Illuminate\Http\Request;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User;
use Laravel\Passport\Exceptions\InvalidAuthTokenException;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The student's Approve on ViStud's consent page (docs/specs/mcp-connections-plan.md §3.2). It writes the connection,
 * with the level they chose, and only then hands over to Passport, which issues the code and sends them back to the
 * chat app. Passport's own approve route writes nothing of ViStud's, so a code it issued is useless: McpPrincipal
 * refuses a token with no connection.
 */
final class ApproveConnection
{
    public function __construct(
        private PrincipalFactory $principals,
        private Connections $connections,
        private ApproveAuthorizationController $passport,
    ) {}

    public function __invoke(Request $request, ResponseInterface $psrResponse): Response
    {
        // The same check Passport makes, before anything is written: this Approve belongs to the page just shown.
        $token = $request->input('auth_token');
        if (! is_string($token) || $token === '' || $request->session()->get('authToken') !== $token) {
            throw InvalidAuthTokenException::different();
        }
        $authRequest = self::pending($request);
        $level = $request->input('level');

        $this->connections->consent(
            $this->principals->fromRequest($request),
            $authRequest->getClient()->getIdentifier(),
            is_string($level) ? $level : '',
        );

        return $this->passport->approve($request, $psrResponse);
    }

    /** The sign-in the page was shown for, read without taking it out of the session (Passport takes it). */
    private static function pending(Request $request): AuthorizationRequestInterface
    {
        $serialized = $request->session()->get('authRequest');
        $authRequest = is_string($serialized) ? unserialize($serialized, ['allowed_classes' => [
            AuthorizationRequest::class,
            Client::class,
            Scope::class,
            User::class,
        ]]) : null;
        if (! $authRequest instanceof AuthorizationRequestInterface) {
            throw InvalidAuthTokenException::different();
        }

        return $authRequest;
    }
}
