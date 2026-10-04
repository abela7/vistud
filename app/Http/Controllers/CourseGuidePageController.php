<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A course's guide: /courses/{workspace}/guide (the talk that sets it up, App\Livewire\Workspaces\GuideChat), with
 * `?for=modules` to add some weeks. The service finds the course first, so another student's ID answers 404.
 */
class CourseGuidePageController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Workspaces $workspaces, string $workspace): View
    {
        return view('workspaces.guide', [
            'workspace' => $workspaces->find($principals->fromRequest($request), $workspace),
            'for' => $request->query('for') === 'modules' ? 'modules' : '',
        ]);
    }
}
