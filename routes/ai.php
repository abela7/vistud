<?php

use App\Http\Middleware\McpNoStore;
use App\Http\Middleware\McpPrincipal;
use App\Mcp\Http\ApproveConnection;
use App\Mcp\Http\PanelPreview;
use App\Mcp\Servers\ViStudServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

/*
| ViStud's MCP server (docs/specs/mcp-connections-plan.md §3.2, M6.1). laravel/mcp loads this file.
|
| - The OAuth discovery documents and dynamic client registration (POST /oauth/register), for Claude, ChatGPT and
|   Gemini to sign in. Passport serves /oauth/authorize and /oauth/token.
| - POST /mcp: the server. Nothing from the web group (no session, no CSRF): an IP throttle, the token (auth:api),
|   the mcp:use scope, the student and their connection (McpPrincipal), then the per-student limit.
| - POST /connections/approve: ViStud's consent step, which writes the connection before Passport issues the code.
*/

Mcp::oauthRoutes();

// Registration answers anyone on the internet, so it is throttled per IP. Passport's /oauth/token has its own throttle.
Route::getRoutes()->refreshNameLookups();
Route::getRoutes()->getByName('mcp.oauth.register')?->middleware('throttle:mcp-register');

Mcp::web('/mcp', ViStudServer::class)
    ->middleware([McpNoStore::class, 'throttle:mcp-ip', 'auth:api', CheckToken::using('mcp:use'), McpPrincipal::class, 'throttle:mcp'])
    ->name('mcp');

Route::middleware(['web', 'auth'])
    ->post('/connections/approve', ApproveConnection::class)
    ->name('mcp.approve');

// The panel HTML for the 320 px browser check. PanelPreview refuses this outside local and the test suite.
Route::middleware('web')->get('/mcp/panels/{panel}', PanelPreview::class)->where('panel', 'quiz|topic|session|confirm|cards');
