<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Study\Folders;
use App\Study\Modules;
use App\Study\ModuleTabs;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A module's or a folder's own page: /workspaces/{workspace}/modules/{module}
 * and /workspaces/{workspace}/folders/{folder}; a module's questions,
 * /workspaces/{workspace}/modules/{module}/questions (`?ask=1` goes to the page for a new one);
 * and a module's study sessions, /workspaces/{workspace}/modules/{module}/sessions.
 * Another student's, or one from another workspace, answers 404 like a missing one.
 * The page itself is App\Livewire\Workspaces\Contents.
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

    public function questions(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Modules $modules, ModuleTabs $tabs, string $workspace, string $module): View|RedirectResponse
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $place = $modules->find($by, $module);
        $place->workspaceId === $details->id || throw new NotFound;

        // The old way to start a question: it has a page of its own now.
        if ($request->boolean('ask')) {
            return redirect()->route('workspaces.questions.create', [$details->id, 'module' => $place->id]);
        }

        return view('workspaces.questions', ['workspace' => $details, 'module' => $place, 'counts' => $tabs->counts($by, $details->id, $place->id)]);
    }

    public function sessions(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Modules $modules, ModuleTabs $tabs, string $workspace, string $module): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $place = $modules->find($by, $module);
        $place->workspaceId === $details->id || throw new NotFound;

        return view('workspaces.module-sessions', ['workspace' => $details, 'module' => $place, 'counts' => $tabs->counts($by, $details->id, $place->id)]);
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
