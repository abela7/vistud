<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A workspace's pages: /workspaces/{workspace}/{section?}. The service finds
 * the workspace first, so another student's ID answers 404 before anything
 * is drawn, exactly like one that doesn't exist.
 */
class WorkspacePageController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Workspaces $workspaces, string $workspace, string $section = 'overview'): View
    {
        $details = $workspaces->find($principals->fromRequest($request), $workspace);

        return view('workspaces.show', ['workspace' => $details, 'section' => $section]);
    }
}
