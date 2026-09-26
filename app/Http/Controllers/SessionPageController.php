<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Study\Sessions;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A study session: /workspaces/{workspace}/sessions/{session}. Another
 * student's session, or one from another workspace, answers 404 like a
 * missing one. The page itself is App\Livewire\Workspaces\StudySession.
 */
class SessionPageController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Sessions $sessions, string $workspace, string $session): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        if ($sessions->find($by, $session)->workspaceId !== $details->id) {
            throw new NotFound;
        }

        return view('workspaces.session', ['workspace' => $details, 'sessionId' => $session]);
    }
}
