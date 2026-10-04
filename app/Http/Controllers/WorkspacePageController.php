<?php

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Study\CourseHome;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Sessions;
use App\Study\Workspaces;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A workspace's pages: /courses/{workspace}/{section?}. The service finds
 * the workspace first, so another student's ID answers 404 before anything
 * is drawn, exactly like one that doesn't exist. The Overview, the course's home,
 * is gathered by App\Study\CourseHome (what to do next, how far, the modules) and
 * also shows what to pick up again: the notes edited last, and whether a session is open.
 */
class WorkspacePageController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Workspaces $workspaces, Modules $modules, CourseHome $courseHome, Notes $notes, Sessions $sessions, string $workspace, string $section = 'overview'): View
    {
        $by = $principals->fromRequest($request);
        $details = $workspaces->find($by, $workspace);
        $data = ['workspace' => $details, 'section' => $section];

        if ($section === 'overview') {
            $recent = $notes->list($by, $details->id);
            usort($recent, fn ($a, $b) => $b->updatedAt <=> $a->updatedAt);
            $places = ["workspace:{$details->id}" => null];
            foreach ($modules->list($by, $details->id) as $module) {
                $places["module:{$module->id}"] = $module->title;
            }
            $home = $courseHome->for($by, $details->id);

            $data += [
                'home' => $home,
                'recent' => array_slice($recent, 0, 3),
                'places' => $places,
                'lastSession' => $home->lastSession,
                'openSession' => $sessions->current($by),
            ];
        }

        return view('workspaces.show', $data);
    }
}
