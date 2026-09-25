<?php

namespace App\Http\Middleware;

use App\Identity\PrincipalFactory;
use App\Identity\Workspaces;
use App\Platform\Access\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `admin.workspace`. Reaching any admin route while in the student workspace
 * counts as entering the admin workspace, which needs a recent password
 * confirmation (ADR 0003 §10.3). Once inside, later admin requests pass;
 * protected actions still check the confirmation themselves.
 */
class EnterAdminWorkspace
{
    public function __construct(private PrincipalFactory $principals, private Workspaces $workspaces) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        if ($session->get(PrincipalFactory::WORKSPACE_KEY) !== Workspace::Admin->value) {
            $this->workspaces->enter($this->principals->fromRequest($request), Workspace::Admin);
            $session->put(PrincipalFactory::WORKSPACE_KEY, Workspace::Admin->value);
            $this->principals->forget($request);
        }

        return $next($request);
    }
}
