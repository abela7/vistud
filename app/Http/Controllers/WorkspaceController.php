<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Identity\Workspaces;
use App\Platform\Access\Workspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Switching workspace. The Workspaces service decides; this stores the choice in the session. */
class WorkspaceController
{
    public function update(Request $request, string $workspace, PrincipalFactory $principals, Workspaces $workspaces): Response
    {
        $target = Workspace::from($workspace);
        $workspaces->enter($principals->fromRequest($request), $target);
        $request->session()->put(PrincipalFactory::WORKSPACE_KEY, $target->value);

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect($target === Workspace::Admin ? '/admin' : '/');
    }
}
