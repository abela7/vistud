<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Study\Activities;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * An assignment on a page of its own, /workspaces/{workspace}/assignments/{assignment}, with its deadline, where
 * the student is with it and its files; and a new one, /workspaces/{workspace}/assignments/new (`module` says
 * where it starts). Another student's assignment, or one from another workspace, answers 404 like a missing
 * one. The page itself is App\Livewire\Workspaces\AssignmentPage (the owner's review, 2026-10-02).
 */
class AssignmentPageController
{
    public function show(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Activities $activities, string $workspace, string $assignment): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $found = $activities->find($by, $assignment);
        $found->workspaceId === $details->id || throw new NotFound;

        return view('workspaces.assignment', ['workspace' => $details, 'assignment' => $found, 'module' => null]);
    }

    public function create(Request $request, PrincipalFactory $principals, Workspaces $workspaces, string $workspace): View
    {
        $details = $workspaces->find($principals->fromRequest($request), $workspace);
        $module = $request->query('module');

        return view('workspaces.assignment', ['workspace' => $details, 'assignment' => null, 'module' => is_string($module) && $module !== '' ? $module : null]);
    }
}
