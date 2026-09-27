<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A module's or a folder's own page: /workspaces/{workspace}/modules/{module}
 * and /workspaces/{workspace}/folders/{folder}; and a module's questions,
 * /workspaces/{workspace}/modules/{module}/questions (`?ask=1` opens a new one). Another student's, or one
 * from another workspace, answers 404 like a missing one. The page itself is
 * App\Livewire\Workspaces\Contents.
 */
class PlacePageController
{
    public function module(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Modules $modules, string $workspace, string $module): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $place = $modules->find($by, $module);
        $place->workspaceId === $details->id || throw new NotFound;

        return view('workspaces.place', ['workspace' => $details, 'view' => 'module', 'placeId' => $place->id, 'title' => $place->title, 'section' => 'modules']);
    }

    public function questions(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Modules $modules, string $workspace, string $module): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $place = $modules->find($by, $module);
        $place->workspaceId === $details->id || throw new NotFound;

        return view('workspaces.questions', ['workspace' => $details, 'module' => $place, 'ask' => $request->boolean('ask')]);
    }

    public function folder(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Folders $folders, string $workspace, string $folder): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $place = $folders->find($by, $folder);
        $place->workspaceId === $details->id || throw new NotFound;

        return view('workspaces.place', ['workspace' => $details, 'view' => 'folder', 'placeId' => $place->id, 'title' => $place->name, 'section' => $place->moduleId !== null ? 'modules' : 'notes']);
    }
}
